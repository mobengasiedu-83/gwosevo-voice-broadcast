<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('turnstile', ['enabled' => true, 'site_key' => 'public-key', 'secret_key' => 'private-key', 'hostnames' => ['localhost'], 'timeout' => 2]);
        Http::preventStrayRequests();
    }

    private function login(array $data = [])
    {
        return $this->post('/login', $data + ['identifier' => 'test@example.com', 'password' => 'password']);
    }

    public function test_missing_token_fails_before_authentication_and_secret_never_appears_in_markup(): void
    {
        $this->get('/login')->assertOk()->assertSee('public-key')->assertDontSee('private-key')->assertSee('cf-turnstile');
        $this->login()->assertSessionHasErrors('turnstile');
        Http::assertNothingSent();
    }

    public function test_success_requires_matching_hostname_and_action_and_cannot_be_replayed(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'login'])]);
        $this->login(['cf-turnstile-response' => 'one-time-token'])->assertSessionHasErrors('email');
        $this->login(['cf-turnstile-response' => 'one-time-token'])->assertSessionHasErrors('turnstile');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['secret'] === 'private-key' && $r['response'] === 'one-time-token');
    }

    public function test_wrong_hostname_wrong_action_and_failed_tokens_are_rejected(): void
    {
        foreach ([['success' => true, 'hostname' => 'attacker.example', 'action' => 'login'], ['success' => true, 'hostname' => 'localhost', 'action' => 'register'], ['success' => false]] as $i => $result) {
            Http::fake(['challenges.cloudflare.com/*' => Http::response($result)]);
            $this->login(['cf-turnstile-response' => 'token-'.$i])->assertSessionHasErrors('turnstile');
        }
    }

    public function test_network_errors_and_incomplete_setup_fail_closed(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::failedConnection()]);
        $this->login(['cf-turnstile-response' => 'test-token'])->assertSessionHasErrors('turnstile');
        config()->set('turnstile.secret_key', '');
        $this->login(['cf-turnstile-response' => 'new-token'])->assertSessionHasErrors('turnstile');
    }

    public function test_disabled_turnstile_does_not_call_cloudflare(): void
    {
        config()->set('turnstile.enabled', false);
        $this->login()->assertSessionHasErrors('email');
        Http::assertNothingSent();
    }

    public function test_turnstile_applies_to_registration_and_recovery_too(): void
    {
        $this->post('/register')->assertSessionHasErrors('turnstile');
        $this->post('/forgot-password')->assertSessionHasErrors('turnstile');
        $this->post('/verify-contact')->assertSessionHasErrors('turnstile');
        $this->post('/set-password')->assertSessionHasErrors('turnstile');
    }

    public function test_expired_tokens_are_rejected_and_never_flashed(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
        $this->login(['cf-turnstile-response' => 'expired-token'])->assertSessionHasErrors('turnstile')->assertSessionMissing('_old_input.cf-turnstile-response');
    }
}
