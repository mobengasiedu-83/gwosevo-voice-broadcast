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
use Illuminate\Support\Facades\Log;
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
        return array_merge(['name' => 'Test Client', 'account_name' => 'Test Account', 'country' => 'Ghana', 'broadcasting_purpose' => 'Customer updates', 'terms' => '1', 'channel' => 'email', 'email' => 'new@example.com', 'phone' => '0241234567'], $overrides);
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
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('+233241234567', $user->phone_e164);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertTrue(Hash::check($this->password, $user->password));
        $this->assertDatabaseHas('client_memberships', ['user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.registered']);
        $this->assertNotNull(DB::table('identity_challenges')->value('consumed_at'));
    }

    public function test_sms_registration_records_both_contacts_and_verifies_only_phone(): void
    {
        Http::fake(['api.txtconnect.net/*' => Http::response(['data' => ['status_code' => '000', 'in_error' => false]], 200)]);
        $this->post('/register', $this->registration(['channel' => 'sms']))->assertRedirect('/verify-contact');
        $request = Http::recorded()->first()[0];
        $this->assertSame('233241234567', $request['to']);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-token'));
        preg_match('/code is ([0-9]{6})/', $request['sms'], $match);
        $this->post('/verify-contact', ['code' => $match[1]])->assertRedirect('/set-password');
        $this->post('/set-password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertSame('+233241234567', $user->phone_e164);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->phone_verified_at);
        Mail::assertNothingSent();
    }

    public function test_registration_requires_a_valid_email_and_mobile_number(): void
    {
        $this->post('/register', $this->registration(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->post('/register', $this->registration(['phone' => 'not-a-number']))->assertSessionHasErrors('phone');
        $this->post('/register', $this->registration(['phone' => '']))->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('identity_challenges', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_registration_does_not_send_a_code_when_the_mobile_number_belongs_to_an_account(): void
    {
        User::factory()->create(['phone_e164' => '+233241234567', 'phone_verified_at' => now()]);
        $this->post('/register', $this->registration())->assertRedirect('/verify-contact');
        Mail::assertNothingSent();
        $this->assertNull(DB::table('identity_challenges')->value('verified_at'));
    }

    public function test_registration_does_not_send_a_code_when_the_email_belongs_to_an_account(): void
    {
        Http::fake();
        User::factory()->create(['email' => 'new@example.com']);
        $this->post('/register', $this->registration(['channel' => 'sms']))->assertRedirect('/verify-contact');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_duplicate_contact_registration_is_logged_as_suppressed(): void
    {
        Log::spy();
        Http::fake();
        User::factory()->create(['phone_e164' => '+233241234567', 'phone_verified_at' => now()]);
        $this->post('/register', $this->registration(['channel' => 'sms']))->assertRedirect('/verify-contact');
        Http::assertNothingSent();
        Log::shouldHaveReceived('notice')->withArgs(fn (string $event, array $context = []) => $event === 'identity.otp_suppressed'
            && ($context['purpose'] ?? null) === 'register'
            && ($context['channel'] ?? null) === 'sms'
            && ($context['reason'] ?? null) === 'duplicate_contact'
            && ($context['identifier_hash'] ?? null) === hash('sha256', '+233241234567'))->atLeast()->once();
    }

    public function test_email_recovery_keeps_using_a_single_identifier_field(): void
    {
        $user = $this->verifiedClient();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $user->email])->assertRedirect('/verify-contact');
        Mail::assertSent(IdentityCode::class, 1);
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
        $this->post('/verify-contact/resend')->assertSessionHasErrors('email');
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
        $this->post('/register', $this->registration(['channel' => 'sms']))->assertSessionHasErrors('phone');
        $this->assertNotNull(DB::table('identity_challenges')->value('consumed_at'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_txtconnect_rejection_is_logged_with_provider_detail_but_not_the_code(): void
    {
        Log::spy();
        Http::fake(['api.txtconnect.net/*' => Http::response(['msg' => 'Insufficient credits', 'data' => ['status_code' => '101', 'message' => 'Insufficient credits', 'in_error' => true]], 200)]);
        $this->post('/register', $this->registration(['channel' => 'sms']))->assertSessionHasErrors('phone');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context = []) => $event === 'identity.sms_failed'
            && ($context['provider_code'] ?? null) === '101'
            && ($context['provider_message'] ?? null) === 'Insufficient credits'
            && array_keys($context) === ['http_status', 'provider_code', 'provider_message', 'identifier_hash'])->once();
    }

    public function test_sms_test_command_normalises_numbers_and_reports_the_provider_result(): void
    {
        Http::fake(['api.txtconnect.net/*' => Http::response(['messageId' => 'S1799_test', 'data' => ['status_code' => '000', 'in_error' => false]], 200)]);
        $this->artisan('identity:sms-test', ['phone' => '0531005130'])
            ->expectsOutputToContain('TxtConnect accepted the SMS for +233531005130. Message id: S1799_test')
            ->assertSuccessful();
        Http::assertSent(fn ($request) => $request['to'] === '233531005130' && $request['from'] === 'VoiceCast' && $request['unicode'] === '0');

        Http::fake(['api.txtconnect.net/*' => Http::response(['msg' => 'Invalid sender', 'data' => ['status_code' => '103', 'in_error' => true]], 200)]);
        $this->artisan('identity:sms-test', ['phone' => '+233531005130'])->expectsOutputToContain('SMS delivery failed')->assertFailed();

        $this->artisan('identity:sms-test', ['phone' => '123'])->expectsOutputToContain('The mobile number is not valid.')->assertFailed();
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
        $this->post('/verify-contact/resend')->assertSessionHasErrors('email');
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
