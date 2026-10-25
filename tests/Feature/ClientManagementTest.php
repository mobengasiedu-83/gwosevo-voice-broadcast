<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClientAuth\Delivery;
use App\Services\ClientAuth\DeliveryException;
use App\Services\ClientAuth\Registration;
use App\Services\Clients\Accounts;
use App\Services\Clients\Invitations;
use App\Services\Clients\Management;
use App\Services\Integrations\Access;
use Database\Seeders\ClientLifecyclePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $other;

    private ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->seed([RolesAndPermissionsSeeder::class, ClientLifecyclePermissionsSeeder::class]);
        Storage::fake('local');
        Mail::fake();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->account = app(Accounts::class)->create($this->admin, 'Test Organization');
        $this->owner = $this->member('owner');
        $foreign = app(Accounts::class)->create($this->admin, 'Foreign Organization');
        $this->other = User::factory()->create();
        $this->other->forceFill(['client_account_id' => $foreign->id, 'client_role' => 'owner'])->save();
        $this->other->assignRole('Client');
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        $u->forceFill(['client_account_id' => $this->account->id, 'client_role' => $role])->save();
        $u->assignRole('Client');

        return $u;
    }

    private function path(string $suffix = ''): string
    {
        return '/client-accounts/'.$this->account->id.$suffix;
    }

    public function test_directory_filters_and_all_canonical_tabs_render_for_authorized_staff(): void
    {
        $this->actingAs($this->admin)->get('/client-accounts?q=Test+Organization')->assertOk()->assertSee('Test Organization')->assertDontSee('Foreign Organization');
        $this->actingAs($this->admin)->get('/client-management')->assertOk()->assertSee('Client Overview')->assertSee('Registration trend');
        foreach (['overview', 'profile', 'users', 'contacts', 'recordings', 'campaigns', 'wallet', 'usage', 'compliance', 'restrictions', 'activity', 'settings', 'pricing'] as $tab) {
            $this->actingAs($this->admin)->get($this->path().'?tab='.$tab)->assertOk();
        }
        foreach (['registrations', 'profiles', 'users', 'verification', 'wallets', 'pricing', 'usage', 'restrictions', 'activity', 'settings'] as $section) {
            $this->actingAs($this->admin)->get('/client-management/'.$section)->assertOk();
        }
    }

    public function test_client_and_unprivileged_staff_cannot_enter_internal_management(): void
    {
        $this->actingAs($this->owner)->get('/client-management')->assertForbidden();
        $this->actingAs($this->owner)->get($this->path())->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/client-management')->assertForbidden();
        $this->actingAs($this->owner)->get('/client/organization?tab=profile')->assertOk();
        $this->actingAs($this->owner)->get('/client/team')->assertOk();
        $this->actingAs($this->owner)->get('/client/organization?tab=activity')->assertForbidden();
    }

    public function test_business_profile_is_scoped_and_invalidates_business_review_without_touching_audio(): void
    {
        $this->account->update(['business_verification' => 'verified', 'business_verified_at' => now()]);
        $audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Reviewed', 'uploaded_by' => $this->owner->id, 'status' => 'approved']);
        $data = ['name' => 'Changed business', 'country' => 'GH', 'registration_number' => 'REG123', 'reason' => 'Correction', 'status' => 'suspended', 'client_account_id' => $this->other->client_account_id];
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/profile', $data)->assertRedirect();
        $this->assertSame('pending', $this->account->fresh()->business_verification);
        $this->assertSame('active', $this->account->fresh()->status);
        $this->assertSame('approved', $audio->fresh()->status);
        $this->actingAs($this->other)->post('/client/organization/'.$this->account->id.'/profile', $data)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.profile_updated', 'client_account_id' => $this->account->id]);
    }

    public function test_private_document_review_and_expiry_require_separate_permission(): void
    {
        $file = UploadedFile::fake()->createWithContent('registration.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/documents', ['name' => 'Registration', 'document' => $file, 'expires_on' => now()->addDay()->toDateString()])->assertRedirect();
        $doc = DB::table('client_documents')->first();
        $this->assertNotNull($doc);
        $this->actingAs($this->other)->get('/client/organization/'.$this->account->id.'/documents/'.$doc->uuid)->assertNotFound();
        $this->actingAs($this->owner)->get('/client/organization/'.$this->account->id.'/documents/'.$doc->uuid)->assertOk()->assertDownload('business-document.pdf');
        $this->actingAs($this->owner)->post($this->path('/business-review'), ['document_id' => $doc->id, 'status' => 'approved', 'reason' => 'Checked'])->assertForbidden();
        $this->actingAs($this->admin)->post($this->path('/business-review'), ['document_id' => $doc->id, 'status' => 'approved', 'reason' => 'Checked evidence'])->assertRedirect();
        $this->assertSame('verified', $this->account->fresh()->business_verification);
        $this->assertTrue(app(Management::class)->validDocument($this->account->id));
        $this->travel(2)->days();
        $this->assertFalse(app(Management::class)->validDocument($this->account->id));
        Storage::disk('local')->put($doc->storage_key, 'tampered');
        $this->actingAs($this->owner)->get('/client/organization/'.$this->account->id.'/documents/'.$doc->uuid)->assertStatus(410);
    }

    public function test_account_managers_do_not_inherit_business_review_or_finance_permissions(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['clients.view', 'clients.profile']);
        $this->actingAs($manager)->get($this->path().'?tab=profile')->assertOk();
        $this->actingAs($manager)->get($this->path().'?tab=wallet')->assertForbidden();
        $this->actingAs($manager)->post($this->path('/business-review'), ['status' => 'approved', 'reason' => 'Not authorized'])->assertForbidden();
        $this->actingAs($manager)->get('/client-management/activity')->assertForbidden();
    }

    public function test_delegated_roles_enforce_server_side_operations_and_report_access(): void
    {
        $viewer = $this->member('viewer');
        $this->actingAs($viewer)->get('/client/contacts')->assertOk();
        $this->actingAs($viewer)->post('/client/contacts', ['name' => 'Denied', 'phone' => '0241234567'])->assertForbidden();
        $this->actingAs($viewer)->get('/client/campaigns/create')->assertForbidden();
        $this->actingAs($viewer)->get('/client/wallet')->assertForbidden();
        $this->actingAs($viewer)->get('/client/integrations')->assertForbidden();
        $this->actingAs($viewer)->get('/client/reports?kind=billing')->assertForbidden();
        $this->actingAs($viewer)->get('/client/team')->assertForbidden();
        $finance = $this->member('finance');
        $this->actingAs($finance)->get('/client/wallet')->assertOk();
        $this->actingAs($finance)->get('/client/reports')->assertOk();
        $this->actingAs($finance)->get('/client/reports?kind=attempts')->assertForbidden();
        $this->actingAs($finance)->post('/client/campaigns/create', [])->assertForbidden();
        $this->assertFalse($viewer->can('audio.approve'));
    }

    public function test_role_change_revokes_sessions_and_keys_and_last_owner_is_protected(): void
    {
        $member = $this->member('administrator');
        app(Access::class)->issue($member, $member, 'Owned key', ['contacts.read']);
        DB::table('sessions')->insert(['id' => 'session-fixture', 'user_id' => $member->id, 'payload' => 'fixture', 'last_activity' => now()->timestamp]);
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/members/'.$member->id, ['role' => 'viewer', 'enabled' => 1, 'reason' => 'Read only'])->assertRedirect();
        $this->assertSame('viewer', $member->fresh()->client_role);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-fixture']);
        $this->assertNotNull(DB::table('api_credentials')->where('user_id', $member->id)->value('revoked_at'));
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/members/'.$this->owner->id, ['role' => 'viewer', 'enabled' => 1, 'reason' => 'Unsafe'])->assertStatus(422);
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/members/'.$this->other->id, ['role' => 'viewer', 'enabled' => 1, 'reason' => 'Wrong account'])->assertNotFound();
    }

    public function test_client_administrator_cannot_promote_to_owner_or_modify_owner(): void
    {
        $a = $this->member('administrator');
        $v = $this->member('viewer');
        $this->actingAs($a)->post('/client/organization/'.$this->account->id.'/members/'.$v->id, ['role' => 'owner', 'enabled' => 1, 'reason' => 'Escalation'])->assertForbidden();
        $this->actingAs($a)->post('/client/organization/'.$this->account->id.'/members/'.$this->owner->id, ['role' => 'viewer', 'enabled' => 1, 'reason' => 'Escalation'])->assertForbidden();
        $this->actingAs($a)->post('/client/organization/'.$this->account->id.'/invitations', ['name' => 'New Owner', 'email' => 'new@example.test', 'role' => 'owner'])->assertForbidden();
    }

    public function test_invitation_acceptance_is_hashed_single_use_and_never_reassigns_existing_users(): void
    {
        $message = '';
        $this->mock(Delivery::class, function ($mock) use (&$message) {
            $mock->shouldReceive('send')->once()->withArgs(function ($channel, $recipient, $body, $subject) use (&$message) {
                $message = $body;

                return $channel === 'email' && $recipient === 'new@example.test';
            });
        });
        app(Invitations::class)->send($this->owner, $this->account, ['name' => 'Invited Viewer', 'email' => 'new@example.test', 'role' => 'viewer']);
        $invite = DB::table('client_invitations')->first();
        preg_match('~/client-invitations/[a-f0-9-]{36}/([A-Za-z0-9]{64})~', $message, $match);
        $token = $match[1];
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertNotSame($token, $invite->token_hash);
        $this->actingAs($this->admin)->post('/logout');
        $this->get('/client-invitations/'.$invite->uuid.'/'.$token)->assertOk()->assertDontSee('token_hash');
        $this->post('/client-invitations/'.$invite->uuid.'/'.$token, ['password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!', 'terms' => 1])->assertRedirect('/login');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($this->account->id, $user->client_account_id);
        $this->assertSame('viewer', $user->client_role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->can('audio.approve'));
        $this->get('/client-invitations/'.$invite->uuid.'/'.$token)->assertStatus(410);
    }

    public function test_launch_and_dispatch_restrictions_are_independent_and_do_not_end_calls(): void
    {
        $campaign = Campaign::create(['name' => 'Owned', 'type' => 'one_way', 'owner_id' => $this->owner->id, 'status' => 'ready']);
        $this->assertTrue(app(Management::class)->dispatchAllowed($campaign));
        $this->account->update(['launch_blocked' => true]);
        $this->assertTrue(app(Management::class)->dispatchAllowed($campaign));
        try {
            app(Management::class)->launch($campaign);
            $this->fail('Launch should be blocked');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->account->update(['dispatch_blocked' => true]);
        $this->assertFalse(app(Accounts::class)->campaignActive($campaign));
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->account->update(['launch_blocked' => false, 'dispatch_blocked' => false, 'max_active_campaigns' => 0]);
        try {
            app(Management::class)->launch($campaign);
            $this->fail('Limit should be enforced');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->account->update(['max_active_campaigns' => null, 'require_both_contacts' => true]);
        $this->assertFalse(app(Management::class)->dispatchAllowed($campaign));
        $this->owner->forceFill(['phone_verified_at' => now()])->save();
        $this->assertTrue(app(Management::class)->dispatchAllowed($campaign));
        $this->owner->forceFill(['client_access_enabled' => false])->save();
        $this->assertFalse(app(Management::class)->dispatchAllowed($campaign));
    }

    public function test_spending_restriction_is_finance_authorized_and_uses_exact_wallet_precision(): void
    {
        $wallet = Wallet::create(['client_account_id' => $this->account->id, 'currency' => 'GHS', 'minor_digits' => 3]);
        $d = ['launch_blocked' => 0, 'dispatch_blocked' => 0, 'require_both_contacts' => 0, 'require_business_verification' => 0, 'daily_spend_limit' => '0.003', 'reason' => 'Confirmed cap'];
        $manager = User::factory()->create();
        $manager->givePermissionTo('clients.restrict');
        $this->actingAs($manager)->post($this->path('/restrictions'), $d)->assertForbidden();
        $this->actingAs($this->admin)->post($this->path('/restrictions'), $d)->assertRedirect();
        $this->assertSame(3, (int) $this->account->fresh()->daily_spend_limit_minor);
        $this->assertTrue(app(Management::class)->spendAllowed($this->account->id, $wallet, 3));
        $this->assertFalse(app(Management::class)->spendAllowed($this->account->id, $wallet, 4));
        $this->actingAs($this->admin)->post($this->path('/restrictions'), array_replace($d, ['daily_spend_limit' => '0.0001']))->assertSessionHasErrors('daily_spend_limit');
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }

    public function test_directory_export_is_authorized_and_guards_spreadsheet_formulas(): void
    {
        $this->account->update(['name' => '=HYPERLINK("bad")']);
        $this->actingAs($this->owner)->get('/client-accounts/export')->assertForbidden();
        $response = $this->actingAs($this->admin)->get('/client-accounts/export')->assertOk()->assertDownload('voicecast-clients.csv');
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.directory_exported']);
    }

    public function test_settings_are_scoped_and_cannot_change_caller_id_or_internal_manager_as_client(): void
    {
        $d = ['timezone' => 'Europe/London', 'language' => 'en', 'import_country' => 'GH', 'reason' => 'Preference', 'caller_id' => '911'];
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/settings', $d)->assertRedirect();
        $this->assertSame('Europe/London', $this->account->fresh()->settings['timezone']);
        $this->assertArrayNotHasKey('caller_id', $this->account->fresh()->settings);
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/settings', $d + ['account_manager_id' => $this->admin->id])->assertForbidden();
    }

    public function test_operator_can_control_account_campaigns_but_cannot_edit_their_content(): void
    {
        $operator = $this->member('operator');
        $campaign = Campaign::create(['name' => 'Owner campaign', 'type' => 'one_way', 'owner_id' => $this->owner->id, 'status' => 'running']);
        $this->actingAs($operator)->post('/client/campaigns/'.$campaign->id.'/control', ['action' => 'pause', 'reason' => 'Operator pause'])->assertRedirect();
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->actingAs($operator)->post('/client/campaigns/'.$campaign->id.'/save', [])->assertForbidden();
        $this->assertFalse($operator->can('telephony.manage'));
    }

    public function test_invitation_cannot_move_staff_and_failure_rolls_back_pending_invitation(): void
    {
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/invitations', ['name' => 'Staff', 'email' => $this->admin->email, 'role' => 'viewer'])->assertStatus(422);
        $this->assertNull($this->admin->fresh()->client_account_id);
        $this->mock(Delivery::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andThrow(new DeliveryException('Unavailable'));
        });
        $this->actingAs($this->owner)->post('/client/organization/'.$this->account->id.'/invitations', ['name' => 'New', 'email' => 'new@example.test', 'role' => 'viewer'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('client_invitations', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client.invitation_sent']);
    }

    public function test_registration_resend_keeps_browser_secret_and_failed_attempt_count_and_enforces_cooldown(): void
    {
        $bodies = [];
        $this->mock(Delivery::class, function ($mock) use (&$bodies) {
            $mock->shouldReceive('send')->twice()->withArgs(function ($channel, $recipient, $body) use (&$bodies) {
                $bodies[] = $body;

                return true;
            });
        });
        [$id, $secret] = app(Registration::class)->start(['name' => 'New signup', 'organization' => 'Signup organization', 'email' => 'signup@example.test', 'phone' => '0241234599', 'country' => 'GH', 'verification_channel' => 'email', 'terms' => 1]);
        DB::table('client_auth_challenges')->where('id', $id)->update(['attempts' => 2]);
        $this->actingAs($this->admin)->post('/client-management/registrations/'.$id.'/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->actingAs($this->admin)->post('/client-management/registrations/'.$id.'/resend')->assertRedirect();
        $challenge = app(Registration::class)->challenge($id, $secret);
        $this->assertSame(2, (int) $challenge->attempts);
        preg_match('/code is ([0-9]{6})/', $bodies[1], $code);
        $this->assertTrue(Hash::check($code[1], $challenge->code_hash));
        $this->actingAs($this->admin)->get('/client-management/registrations')->assertOk()->assertDontSee($code[1])->assertDontSee($challenge->secret_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.registration_code_resent']);
    }

    public function test_held_exposure_and_posted_call_debits_consume_daily_cap_without_spending_new_money(): void
    {
        $wallet = Wallet::create(['client_account_id' => $this->account->id, 'currency' => 'GHS', 'minor_digits' => 3]);
        $this->account->update(['daily_spend_limit_minor' => 10]);
        DB::table('wallet_ledger_entries')->insert(['wallet_id' => $wallet->id, 'kind' => 'call_charge', 'amount_minor' => -3, 'balance_after_minor' => 97, 'idempotency_key' => 'fixture-call', 'reason' => 'Verified fixture', 'created_at' => now()]);
        $attempt = CallAttempt::create(['uuid' => (string) Str::uuid(), 'state' => 'authorizing']);
        DB::table('wallet_reservations')->insert(['wallet_id' => $wallet->id, 'call_attempt_id' => $attempt->id, 'amount_minor' => 4, 'held_minor' => 4, 'charged_minor' => 0, 'status' => 'held', 'policy' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(app(Management::class)->spendAllowed($this->account->id, $wallet, 3));
        $this->assertFalse(app(Management::class)->spendAllowed($this->account->id, $wallet, 4));
        $this->assertTrue(app(Management::class)->spendAllowed($this->account->id, $wallet, 4, $attempt->id));
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_permission_registration_does_not_expand_existing_administrator_grants(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('Administrator');
        $this->assertTrue($staff->can('clients.manage'));
        foreach (['clients.profile', 'clients.users', 'clients.verify', 'clients.restrict', 'clients.export', 'clients.activity', 'clients.settings'] as $permission) {
            $this->assertFalse($staff->can($permission));
        }
        $this->actingAs($staff)->get('/client-accounts')->assertOk();
        $this->actingAs($staff)->post($this->path('/business-review'), ['status' => 'approved', 'reason' => 'No grant'])->assertForbidden();
        $this->assertTrue($this->admin->can('clients.verify'));
    }

    public function test_explicit_restriction_grant_can_suspend_without_granting_creation_or_finance(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(['clients.view', 'clients.restrict']);
        $this->actingAs($staff)->post($this->path('/status'), ['status' => 'suspended', 'reason' => 'Authorized account restriction'])->assertRedirect();
        $this->assertSame('suspended', $this->account->fresh()->status);
        $this->actingAs($staff)->post('/client-accounts', ['name' => 'Not authorized'])->assertForbidden();
        $this->assertFalse($staff->can('billing.manage'));
    }

    public function test_authentication_activity_records_unknown_failed_actor_and_verified_login_history(): void
    {
        $this->owner->forceFill(['password' => 'StrongPass123!'])->save();
        $this->post('/login', ['identifier' => $this->owner->email, 'password' => 'Incorrect123!'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.login_failed', 'actor_id' => null, 'client_account_id' => $this->account->id]);
        $this->post('/login', ['identifier' => $this->owner->email, 'password' => 'StrongPass123!'])->assertRedirect('/client');
        $this->assertNotNull($this->owner->fresh()->last_login_at);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.login', 'actor_id' => $this->owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.logout', 'actor_id' => $this->owner->id]);
        $this->assertStringNotContainsString('StrongPass123!', json_encode(DB::table('audit_logs')->pluck('details')));
    }
}
