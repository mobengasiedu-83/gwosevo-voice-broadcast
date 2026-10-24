<?php

namespace Tests\Feature;

use App\Models\ClientAuthChallenge;
use App\Models\User;
use App\Services\ClientAuth\ContactVerification;
use App\Services\ClientAuth\Delivery;
use App\Services\ClientAuth\DeliveryException;
use App\Services\ClientAuth\Recovery;
use App\Services\ClientAuth\Registration;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    private string $password = 'StrongPassword!123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mock(Delivery::class)->shouldReceive('send')->andReturnUsing(function ($channel, $identifier, $message) {
            $this->sent[] = compact('channel', 'identifier', 'message');
        });
    }

    private function code(): string
    {
        preg_match('/code is (\d{6})/', end($this->sent)['message'], $m);

        return $m[1];
    }

    private function signupData(string $identifier = 'client@example.com', array $extra = []): array
    {
        $email = str_contains($identifier, '@');

        return array_replace(['name' => 'Client', 'organization' => 'Client Org',
            'email' => $email ? $identifier : 'mobile@example.com',
            'phone' => $email ? '024'.str_pad((string) (crc32($identifier) % 10000000), 7, '0', STR_PAD_LEFT) : $identifier,
            'verification_channel' => $email ? 'email' : 'phone', 'country' => 'GH', 'terms' => '1'], $extra);
    }

    private function register(string $identifier = 'client@example.com'): User
    {
        $service = app(Registration::class);
        [$id, $secret] = $service->start($this->signupData($identifier));
        $service->verify($id, $secret, $this->code());

        return $service->finish($id, $secret, $this->password);
    }

    public function test_placeholder_policies_are_public_and_linked_from_signup(): void
    {
        $this->get('/AcceptTerms&PrivacyPolicy')->assertOk()->assertSee('Dummy content for development.')->assertSee('Terms of service')->assertSee('Privacy policy');
        $this->get('/register')->assertOk()->assertSee(route('legal.policies').'#terms')->assertSee(route('legal.policies').'#privacy');
        $u = $this->register();
        $this->actingAs($u)->get('/AcceptTerms&PrivacyPolicy')->assertOk();
    }

    public function test_signup_requires_both_contacts_country_and_terms_and_normalizes_duplicates(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['email', 'phone', 'country', 'terms', 'verification_channel'] as $field) {
            $data = $this->signupData();
            unset($data[$field]);
            $this->post('/register', $data)->assertSessionHasErrors($field);
        }
        $this->assertCount(0, $this->sent);
        $u = $this->register();
        $this->post('/register', $this->signupData(strtoupper($u->email)))->assertSessionHasErrors('email');
        $this->post('/register', $this->signupData('new@example.com', ['phone' => $u->phone_e164]))->assertSessionHasErrors('email');
        $this->post('/register', $this->signupData('new@example.com', ['country' => 'US']))->assertSessionHasErrors('country');
    }

    public function test_either_saved_contact_can_login_but_only_verified_contact_can_recover(): void
    {
        $u = $this->register();
        $before = count($this->sent);
        app(Recovery::class)->send($u->phone_e164);
        $this->assertCount($before, $this->sent);
        foreach ([$u->phone_e164, $u->email] as $identifier) {
            $this->post('/login', ['identifier' => $identifier, 'password' => $this->password])->assertRedirect('/client');
            $this->post('/logout');
        }
        $this->assertCount($before, $this->sent);
    }

    public function test_second_contact_verification_is_session_bound_and_replacement_waits_for_valid_code(): void
    {
        $u = $this->register();
        $this->actingAs($u)->get('/client/settings')->assertOk()->assertSee('Contact verification')->assertSee('Not verified');
        $this->post('/client/settings/contact', ['channel' => 'phone', 'contact' => $u->phone_e164, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/client/settings/contact', ['channel' => 'phone', 'contact' => $u->phone_e164, 'current_password' => $this->password])->assertRedirect();
        $this->assertNull($u->fresh()->phone_verified_at);
        $this->post('/client/settings/contact/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/client/settings/contact/verify', ['code' => $this->code()])->assertRedirect();
        $this->assertNotNull($u->fresh()->phone_verified_at);
        $this->assertDatabaseHas('client_otp_logs', ['user_id' => $u->id, 'purpose' => 'contact', 'status' => 'consumed', 'verification_attempts' => 2]);
        $this->travel(61)->seconds();
        $oldEmail = $u->email;
        $this->post('/client/settings/contact', ['channel' => 'email', 'contact' => 'replacement@example.com', 'current_password' => $this->password])->assertRedirect();
        $this->assertSame($oldEmail, $u->fresh()->email);
        $this->post('/client/settings/contact/verify', ['code' => $this->code()])->assertRedirect();
        $this->assertSame('replacement@example.com', $u->fresh()->email);
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->post('/client/settings/contact/verify', ['code' => $this->code()])->assertSessionHasErrors('code');
    }

    public function test_contact_codes_enforce_owner_expiry_attempt_limits_and_cooldown(): void
    {
        $u = $this->register();
        $verification = app(ContactVerification::class);
        [$id, $secret] = $verification->start($u, 'phone', $u->phone_e164);
        try {
            $verification->start($u, 'phone', $u->phone_e164);
            $this->fail('Cooldown bypassed');
        } catch (ValidationException $e) {
        }
        $other = User::factory()->create();
        $other->forceFill(['client_account_id' => $u->client_account_id])->save();
        $other->assignRole('Client');
        try {
            $verification->verify($other, $id, $secret, $this->code());
            $this->fail('Foreign code accepted');
        } catch (ValidationException $e) {
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                $verification->verify($u, $id, $secret, '000000');
            } catch (ValidationException $e) {
            }
        }
        $this->assertDatabaseHas('client_otp_logs', ['challenge_id' => $id, 'status' => 'locked', 'verification_attempts' => 5]);
        $this->travel(61)->seconds();
        [$id, $secret] = $verification->start($u, 'phone', $u->phone_e164);
        $this->travel(5)->minutes();
        $this->expectException(ValidationException::class);
        $verification->verify($u, $id, $secret, $this->code());
    }

    public function test_contact_uniqueness_is_rechecked_when_registration_finishes(): void
    {
        $r = app(Registration::class);
        [$id, $secret] = $r->start($this->signupData());
        $r->verify($id, $secret, $this->code());
        User::factory()->create(['email' => 'client@example.com']);
        $this->expectException(ValidationException::class);
        $r->finish($id, $secret, $this->password);
    }

    public function test_otp_history_tracks_resends_verification_and_client_link_without_storing_codes(): void
    {
        $r = app(Registration::class);
        [$id, $secret] = $r->start($this->signupData('0241234567', ['name' => 'A', 'organization' => 'A']));
        $firstCode = $this->code();
        $this->assertDatabaseHas('client_otp_logs', ['challenge_id' => $id, 'status' => 'sent', 'channel' => 'sms', 'client_account_id' => null]);
        $this->travel(61)->seconds();
        $r->resend($id, $secret);
        $this->assertDatabaseHas('client_otp_logs', ['challenge_id' => $id, 'status' => 'superseded']);
        $r->verify($id, $secret, $this->code());
        $user = $r->finish($id, $secret, $this->password);
        $this->assertDatabaseHas('client_otp_logs', ['challenge_id' => $id, 'status' => 'consumed', 'user_id' => $user->id, 'client_account_id' => $user->client_account_id, 'verification_attempts' => 1]);
        $rows = DB::table('client_otp_logs')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNotNull($row->sent_at);
            $this->assertNotNull($row->expires_at);
            $this->assertSame($user->client_account_id, $row->client_account_id);
            $this->assertStringNotContainsString($firstCode, json_encode($row));
            $this->assertStringNotContainsString($this->code(), json_encode($row));
        }
        ClientAuthChallenge::whereKey($id)->delete();
        $this->assertDatabaseCount('client_otp_logs', 2);
    }

    public function test_email_registration_verifies_before_creating_password_and_account(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create your account');
        $this->post('/register', $this->signupData('Alice@Example.com', ['name' => 'Alice', 'organization' => 'Alpha']))->assertRedirect(route('client.register.verify'));
        $this->assertSame(0, User::count());
        $c = ClientAuthChallenge::first();
        $this->assertNotSame($this->code(), $c->code_hash);
        $this->post('/register/password', ['password' => $this->password, 'password_confirmation' => $this->password])->assertForbidden();
        $this->post('/register/verify', ['code' => $this->code()])->assertRedirect();
        $this->get('/register/verify')->assertOk()->assertSee('Create your password');
        $this->post('/register/password', ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/register/password', ['password' => $this->password, 'password_confirmation' => $this->password, 'role' => 'Super Administrator', 'client_account_id' => 99])->assertRedirect('/client');
        $u = User::first();
        $this->assertTrue($u->isClient());
        $this->assertTrue($u->email_verified_at !== null);
        $this->assertTrue(Hash::check($this->password, $u->password));
        $this->assertSame('Alpha', $u->clientAccount->name);
        $this->assertNotNull($u->phone_e164);
        $this->assertNull($u->phone_verified_at);
        $this->assertSame('GH', $u->country);
        $this->assertNotNull($u->terms_accepted_at);
        $this->assertSame(config('client_auth.terms_version'), $u->terms_version);
        $this->assertSame(['Client'], $u->getRoleNames()->all());
        $this->get('/dashboard')->assertForbidden();
        $this->get('/client/settings')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.registered']);
    }

    public function test_dual_contact_registration_with_sms_verification_and_password_login_are_normalized(): void
    {
        $u = $this->register('0241234567');
        $this->assertSame('mobile@example.com', $u->email);
        $this->assertNull($u->email_verified_at);
        $this->assertSame('+233241234567', $u->phone_e164);
        $this->assertNotNull($u->phone_verified_at);
        $this->post('/login', ['identifier' => '00233241234567', 'password' => $this->password])->assertRedirect('/client');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout');
        $this->post('/login', ['identifier' => '0241234567', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_codes_are_session_bound_expire_and_cannot_be_replayed(): void
    {
        $r = app(Registration::class);
        [$id, $secret] = $r->start($this->signupData('a@example.com', ['name' => 'A', 'organization' => 'A']));
        try {
            $r->verify($id, 'wrong', $this->code());
            $this->fail('Wrong session accepted');
        } catch (ValidationException $e) {
            $this->assertSame(0, ClientAuthChallenge::find($id)->attempts);
        }
        $r->verify($id, $secret, $this->code());
        $r->finish($id, $secret, $this->password);
        try {
            $r->finish($id, $secret, $this->password);
            $this->fail('Replay accepted');
        } catch (ValidationException $e) {
            $this->assertSame(1, User::count());
        }
        [$id, $secret] = $r->start($this->signupData('b@example.com', ['name' => 'B', 'organization' => 'B']));
        $this->travel(5)->minutes();
        $this->expectException(ValidationException::class);
        $r->verify($id, $secret, $this->code());
    }

    public function test_wrong_code_limit_persists_and_resend_does_not_reset_it(): void
    {
        $r = app(Registration::class);
        [$id, $secret] = $r->start($this->signupData('a@example.com', ['name' => 'A', 'organization' => 'A']));
        $oldCode = $this->code();
        for ($i = 0; $i < 2; $i++) {
            try {
                $r->verify($id, $secret, '000000');
            } catch (ValidationException $e) {
            }
        }
        $this->travel(61)->seconds();
        $r->resend($id, $secret);
        $this->assertSame(2, ClientAuthChallenge::find($id)->attempts);
        $this->assertNotEquals($oldCode, $this->code());
        for ($i = 0; $i < 3; $i++) {
            try {
                $r->verify($id, $secret, '000000');
            } catch (ValidationException $e) {
            }
        }
        $this->assertSame(5, ClientAuthChallenge::find($id)->attempts);
        $this->expectException(ValidationException::class);
        $r->verify($id, $secret, $this->code());
    }

    public function test_suspended_clients_cannot_login_or_recover(): void
    {
        $u = $this->register();
        $u->clientAccount->update(['status' => 'suspended']);
        $this->post('/login', ['identifier' => $u->email, 'password' => $this->password])->assertSessionHasErrors('email');
        $this->assertGuest();
        $count = count($this->sent);
        $this->post('/forgot-password', ['identifier' => $u->email])->assertSessionHas('status');
        $this->assertCount($count, $this->sent);
    }

    public function test_recovery_link_is_single_use_and_revokes_sessions(): void
    {
        $u = $this->register();
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $this->post('/forgot-password', ['identifier' => $u->email])->assertSessionHas('status');
        preg_match('~https?://\S+~', end($this->sent)['message'], $m);
        $url = $m[0];
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $new = 'NewPassword!12345';
        $this->post($url, ['password' => $new, 'password_confirmation' => $new])->assertRedirect('/login');
        $this->assertTrue(Hash::check($new, $u->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
        $this->post($url, ['password' => $this->password, 'password_confirmation' => $this->password])->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check($new, $u->fresh()->password));
    }

    public function test_settings_require_current_password_and_cannot_change_identity_or_role(): void
    {
        $u = $this->register();
        $this->actingAs($u)->post('/client/settings', ['name' => 'New', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/client/settings', ['name' => 'New', 'current_password' => $this->password, 'email' => 'other@example.com', 'client_account_id' => 999, 'role' => 'Super Administrator'])->assertRedirect();
        $this->assertSame('New', $u->fresh()->name);
        $this->assertSame('client@example.com', $u->fresh()->email);
        $this->assertFalse($u->fresh()->isSuperAdministrator());
    }

    public function test_delivery_failure_does_not_leave_a_usable_registration(): void
    {
        $this->mock(Delivery::class)->shouldReceive('send')->andThrow(new DeliveryException('secret provider response'));
        $this->post('/register', $this->signupData('a@example.com', ['name' => 'A', 'organization' => 'A']))->assertSessionHasErrors('identifier');
        $this->assertSame(0, ClientAuthChallenge::count());
        $this->assertSame(0, User::count());
    }

    public function test_txtconnect_transport_validates_provider_acknowledgement(): void
    {
        config(['client_auth.sms.driver' => 'txtconnect', 'client_auth.sms.token' => 'test-token']);
        Http::fake(['api.txtconnect.net/*' => Http::sequence()->push(['data' => ['status_code' => '000', 'in_error' => false]], 200)->push(['data' => ['in_error' => true]], 200)]);
        (new Delivery)->send('phone', '+233241234567', 'Test code');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.txtconnect.net/dev/api/sms/send' && $r['to'] === '233241234567' && $r['sms'] === 'Test code' && $r->hasHeader('Authorization', 'Bearer test-token'));
        $this->expectException(\RuntimeException::class);
        (new Delivery)->send('phone', '+233241234567', 'Test code');
    }

    public function test_resend_cooldown_and_delivery_limits_are_enforced(): void
    {
        $r = app(Registration::class);
        [$id, $secret] = $r->start($this->signupData('a@example.com', ['name' => 'A', 'organization' => 'A']));
        try {
            $r->resend($id, $secret);
            $this->fail('Cooldown bypassed');
        } catch (ValidationException $e) {
            $this->assertCount(1, $this->sent);
        }
        $this->travel(61)->seconds();
        $r->resend($id, $secret);
        $this->travel(61)->seconds();
        $r->resend($id, $secret);
        $this->travel(61)->seconds();
        $this->expectException(ValidationException::class);
        $r->resend($id, $secret);
    }

    public function test_expired_and_superseded_recovery_links_cannot_reset_password(): void
    {
        $u = $this->register('0241234567');
        $recovery = app(Recovery::class);
        $recovery->send($u->phone_e164);
        preg_match('~https?://\S+~', end($this->sent)['message'], $m);
        $old = $m[0];
        $recovery->send($u->phone_e164);
        preg_match('~https?://\S+~', end($this->sent)['message'], $m);
        $new = $m[0];
        $this->get($old)->assertRedirect(route('client.password.forgot'));
        $this->get($new)->assertOk();
        $this->travel(31)->minutes();
        $this->get($new)->assertRedirect(route('client.password.forgot'));
        $this->assertTrue(Hash::check($this->password, $u->fresh()->password));
    }

    public function test_password_changes_revoke_sessions_and_recovery_links(): void
    {
        $u = $this->register();
        app(Recovery::class)->send($u->email);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $new = 'ChangedPassword!123';
        $this->actingAs($u)->post('/client/settings', ['name' => 'Changed', 'current_password' => $this->password, 'password' => $new, 'password_confirmation' => $new])->assertRedirect();
        $this->assertTrue(Hash::check($new, $u->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertNotNull(ClientAuthChallenge::where('purpose', 'recovery')->first()->consumed_at);
    }

    public function test_unknown_recovery_has_same_public_response_and_registration_can_be_disabled(): void
    {
        $this->post('/forgot-password', ['identifier' => 'nobody@example.com'])->assertSessionHas('status');
        $this->assertCount(0, $this->sent);
        $this->assertSame(0, ClientAuthChallenge::count());
        config(['client_auth.registration_enabled' => false]);
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->signupData('a@example.com', ['name' => 'A', 'organization' => 'A']))->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_email_delivery_and_unconfigured_sms_are_not_reported_as_success(): void
    {
        config(['mail.default' => 'array']);
        (new Delivery)->send('email', 'client@example.com', 'Registration code');
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        config(['client_auth.sms.driver' => 'disabled']);
        $this->expectException(\RuntimeException::class);
        (new Delivery)->send('phone', '+233241234567', 'Registration code');
    }
}
