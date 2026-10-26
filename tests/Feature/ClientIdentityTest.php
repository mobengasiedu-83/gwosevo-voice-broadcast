<?php

namespace Tests\Feature;

use App\Mail\IdentityCode;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ClientIdentityTest extends TestCase
{
    use RefreshDatabase;

    private string $password = 'StrongPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('turnstile.enabled', false);
        config()->set('client_auth.registration_enabled', true);
        config()->set('client_auth.auto_activate', true);
        config()->set('client_auth.sms_driver', 'txtconnect');
        config()->set('client_auth.sms_token', 'test-token');
        config()->set('client_auth.sms_sender', 'VoiceCast');
        config()->set('client_auth.sms_url', 'https://api.txtconnect.net/dev/api/sms/send');
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge(['name' => 'Test Client', 'account_name' => 'Test Account', 'country' => 'Ghana', 'broadcasting_purpose' => 'Customer updates', 'terms' => '1', 'channel' => 'email', 'identifier' => 'new@example.com'], $overrides);
    }

    private function code(): string
    {
        $messages = Mail::sent(IdentityCode::class);
        $this->assertNotEmpty($messages);

        return $messages->last()->code;
    }

    private function verifiedClient(): User
    {
        $client = Client::create(['name' => 'Existing client', 'status' => 'active']);
        $user = User::factory()->create(['password' => $this->password]);
        ClientMembership::create(['client_id' => $client->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $user->assignRole('Client Member');

        return $user;
    }

    public function test_registration_verifies_email_before_creating_account_and_setting_password(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', $this->registration())->assertRedirect('/verify-contact');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertSessionHasErrors('code');
        $code = $this->code();
        $this->assertNotSame($code, DB::table('identity_challenges')->value('code_hash'));
        $this->post('/verify-contact', ['code' => $code])->assertRedirect('/set-password');
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertTrue(Hash::check($this->password, $user->password));
        $this->assertDatabaseHas('client_memberships', ['user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.registered']);
        $this->assertNotNull(DB::table('identity_challenges')->value('consumed_at'));
    }

    public function test_sms_registration_reuses_txtconnect_configuration_and_verifies_only_phone(): void
    {
        Http::fake(['api.txtconnect.net/*' => Http::response(['data' => ['status_code' => '000', 'in_error' => false]], 200)]);
        $this->post('/register', $this->registration(['channel' => 'sms', 'identifier' => '0241234567']))->assertRedirect('/verify-contact');
        $request = Http::recorded()->first()[0];
        $this->assertSame('233241234567', $request['to']);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-token'));
        preg_match('/code is ([0-9]{6})/', $request['sms'], $match);
        $this->post('/verify-contact', ['code' => $match[1]])->assertRedirect('/set-password');
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertSame('+233241234567', $user->phone_e164);
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->phone_verified_at);
        Mail::assertNothingSent();
    }

    public function test_otp_expiry_and_session_binding_are_enforced(): void
    {
        $this->post('/register', $this->registration());
        $code = $this->code();
        $this->withSession(['identity.secret' => 'forged'])->post('/verify-contact', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull(DB::table('identity_challenges')->value('verified_at'));
        $this->travel(11)->minutes();
        $this->post('/verify-contact', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_wrong_code_attempts_commit_and_lock_out_the_challenge(): void
    {
        $this->post('/register', $this->registration());
        $correct = $this->code();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-contact', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, DB::table('identity_challenges')->value('attempts'));
        $this->post('/verify-contact', ['code' => $correct])->assertSessionHasErrors('code');
        $this->assertNull(DB::table('identity_challenges')->value('verified_at'));
    }

    public function test_verified_code_cannot_be_replayed(): void
    {
        $this->post('/register', $this->registration());
        $code = $this->code();
        $this->post('/verify-contact', ['code' => $code])->assertRedirect('/set-password');
        $this->post('/verify-contact', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_resend_cooldown_and_previous_challenge_invalidation(): void
    {
        $this->post('/register', $this->registration());
        $oldId = DB::table('identity_challenges')->value('id');
        $this->post('/verify-contact/resend')->assertSessionHasErrors('identifier');
        $this->travel(61)->seconds();
        $this->post('/verify-contact/resend')->assertRedirect('/verify-contact');
        $this->assertNotNull(DB::table('identity_challenges')->where('id', $oldId)->value('consumed_at'));
        Mail::assertSent(IdentityCode::class, 2);
    }

    public function test_duplicate_registration_and_unknown_recovery_have_generic_responses(): void
    {
        User::factory()->create(['email' => 'new@example.com']);
        $this->post('/register', $this->registration())->assertRedirect('/verify-contact');
        Mail::assertNothingSent();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => 'unknown@example.com'])->assertRedirect('/verify-contact');
        Mail::assertNothingSent();
    }

    public function test_password_strength_confirmation_and_terms_are_required(): void
    {
        $this->post('/register', $this->registration(['terms' => '0']))->assertSessionHasErrors('terms');
        $this->post('/register', $this->registration());
        $this->post('/verify-contact', ['code' => $this->code()]);
        $this->post('/set-password', ['password' => 'weak', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_can_require_internal_activation(): void
    {
        config()->set('client_auth.auto_activate', false);
        $this->post('/register', $this->registration());
        $this->post('/verify-contact', ['code' => $this->code()]);
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('clients', ['status' => 'pending']);
    }

    public function test_registration_switch_disables_both_form_and_submission(): void
    {
        config()->set('client_auth.registration_enabled', false);
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->registration())->assertNotFound();
    }

    public function test_existing_clients_sign_in_with_verified_email_or_phone_without_otp(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['phone_e164' => '+233241234567', 'phone_verified_at' => now()])->save();
        $this->post('/login', ['identifier' => '0241234567', 'password' => $this->password])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_email_verified_client_can_use_its_recorded_phone_but_suspended_accounts_are_denied(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['phone_e164' => '+233241234567', 'phone_verified_at' => null])->save();
        $this->post('/login', ['identifier' => '0241234567', 'password' => $this->password])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->phone_verified_at);
        Http::assertNothingSent();
        $this->post('/logout');
        $user->clientMembership->client->update(['status' => 'suspended']);
        $this->post('/login', ['identifier' => $user->email, 'password' => $this->password])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_recovery_requires_verification_changes_password_and_is_single_use(): void
    {
        $user = $this->verifiedClient();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $user->email])->assertRedirect('/verify-contact');
        $this->post('/set-password', ['password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!'])->assertSessionHasErrors('code');
        $this->post('/verify-contact', ['code' => $this->code()])->assertRedirect('/set-password');
        $this->post('/set-password', ['password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(Hash::check('NewStrongPassword123!', $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.password_reset']);
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertStatus(422);
    }

    public function test_contact_change_requires_password_and_new_contact_verification(): void
    {
        $user = $this->verifiedClient();
        $old = $user->email;
        $this->actingAs($user)->post('/account/security/contact', ['channel' => 'email', 'identifier' => 'replacement@example.com', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/account/security/contact', ['channel' => 'email', 'identifier' => 'replacement@example.com', 'current_password' => $this->password])->assertRedirect('/verify-contact');
        $this->assertSame($old, $user->fresh()->email);
        $this->post('/verify-contact', ['code' => $this->code()])->assertRedirect('/login');
        $this->assertSame('replacement@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_password_reset_version_invalidates_existing_sessions(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['auth_version' => 1])->save();
        $this->actingAs($user)->withSession(['auth_version' => 0])->get('/contacts')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_txtconnect_rejected_response_never_leaves_a_usable_challenge(): void
    {
        Http::fake(['api.txtconnect.net/*' => Http::response(['data' => ['status_code' => '100', 'in_error' => true]], 200)]);
        $this->post('/register', $this->registration(['channel' => 'sms', 'identifier' => '0241234567']))->assertSessionHasErrors('identifier');
        $this->assertNotNull(DB::table('identity_challenges')->value('consumed_at'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_code_cannot_verify_or_create_an_account(): void
    {
        $this->post('/register', $this->registration());
        $code = $this->code();
        $this->travel(11)->minutes();
        $this->post('/verify-contact', ['code' => $code])->assertSessionHasErrors('code');
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_recovery_does_not_use_an_unverified_client_identifier(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $user->email])->assertRedirect('/verify-contact');
        Mail::assertNothingSent();
    }

    public function test_recovery_does_not_reactivate_a_suspended_account(): void
    {
        $user = $this->verifiedClient();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $user->email]);
        $this->post('/verify-contact', ['code' => $this->code()]);
        $user->clientMembership->client->update(['status' => 'suspended']);
        $this->post('/set-password', ['password' => 'NewStrongPassword123!', 'password_confirmation' => 'NewStrongPassword123!'])->assertForbidden();
        $this->assertTrue(Hash::check($this->password, $user->fresh()->password));
    }

    public function test_contact_change_grant_is_bound_to_the_authenticated_user(): void
    {
        $a = $this->verifiedClient();
        $b = $this->verifiedClient();
        $this->actingAs($a)->post('/account/security/contact', ['channel' => 'email', 'identifier' => 'replacement@example.com', 'current_password' => $this->password]);
        $code = $this->code();
        $this->actingAs($b)->post('/verify-contact', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNotSame('replacement@example.com', $b->fresh()->email);
    }

    public function test_verification_codes_are_not_flashed_into_the_session_on_error(): void
    {
        $this->post('/register', $this->registration());
        $this->post('/verify-contact', ['code' => '000000'])->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $challenge = DB::table('identity_challenges')->first();
        $this->assertNotSame('new@example.com', $challenge->identifier);
        $this->assertStringNotContainsString('Test Account', $challenge->details);
    }

    public function test_send_limits_apply_to_identity_even_after_cooldown(): void
    {
        $this->post('/register', $this->registration());
        for ($i = 0; $i < 2; $i++) {
            $this->travel(61)->seconds();
            $this->post('/verify-contact/resend')->assertRedirect('/verify-contact');
        }
        $this->travel(61)->seconds();
        $this->post('/verify-contact/resend')->assertSessionHasErrors('identifier');
        Mail::assertSent(IdentityCode::class, 3);
    }

    public function test_password_form_requires_prior_verification(): void
    {
        $this->post('/register', $this->registration());
        $this->get('/set-password')->assertRedirect('/verify-contact');
    }

    public function test_old_challenges_are_pruned_without_removing_current_challenges(): void
    {
        $this->post('/register', $this->registration());
        $id = DB::table('identity_challenges')->value('id');
        DB::table('identity_challenges')->where('id', $id)->update(['expires_at' => now()->subDays(8)]);
        $this->artisan('identity:prune')->expectsOutputToContain('Pruned 1')->assertSuccessful();
        $this->assertDatabaseCount('identity_challenges', 0);
    }

    public function test_preprovisioned_client_verifies_contact_once_after_a_valid_password(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->post('/login', ['identifier' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();
        $this->post('/login', ['identifier' => $user->email, 'password' => $this->password])->assertRedirect('/verify-contact');
        $this->assertGuest();
        $this->post('/verify-contact', ['code' => $this->code()])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_phone_verified_client_can_sign_in_with_recorded_email_without_delivery_or_marking_it_verified(): void
    {
        $user = $this->verifiedClient();
        $user->forceFill(['email_verified_at' => null, 'phone_e164' => '+233241234567', 'phone_verified_at' => now()])->save();
        $this->post('/login', ['identifier' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();
        $this->post('/login', ['identifier' => $user->email, 'password' => $this->password])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $user->email])->assertRedirect('/verify-contact');
        Mail::assertNothingSent();
    }

    public function test_login_and_logout_preserve_the_application_subdirectory(): void
    {
        $user = $this->verifiedClient();
        URL::forceRootUrl('http://localhost/gwosevo-voice-broadcast/public');
        try {
            $this->post('http://localhost/login', ['identifier' => $user->email, 'password' => $this->password])->assertRedirect('http://localhost/gwosevo-voice-broadcast/public/dashboard');
            $this->post('http://localhost/logout')->assertRedirect('http://localhost/gwosevo-voice-broadcast/public/login');
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
