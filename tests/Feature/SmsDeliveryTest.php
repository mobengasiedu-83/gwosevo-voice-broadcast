<?php

namespace Tests\Feature;

use App\Services\ClientAuth\Delivery;
use App\Services\ClientAuth\DeliveryException;
use App\Services\ClientAuth\Registration;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['client_auth.sms.driver' => 'txtconnect', 'client_auth.sms.token' => 'test-only-token', 'client_auth.sms.sender' => 'ConfiguredSender', 'client_auth.sms.url' => 'https://api.txtconnect.net/dev/api/sms/send']);
    }

    public static function numbers(): array
    {
        return [['0531005130'], ['233531005130'], ['+233531005130'], ['00233531005130'], ['053 100 5130']];
    }

    #[DataProvider('numbers')]
    public function test_recipient_is_country_code_digits_and_sender_comes_from_configuration(string $number): void
    {
        Http::fake(['api.txtconnect.net/*' => Http::response(['data' => ['status_code' => '000', 'in_error' => false]], 200)]);
        app(Delivery::class)->send('phone', $number, 'Delivery normalization test');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === '233531005130' && $request['from'] === 'ConfiguredSender' && $request['sms'] === 'Delivery normalization test' && $request->hasHeader('Authorization', 'Bearer test-only-token'));
    }

    public function test_invalid_recipient_fails_before_provider_request(): void
    {
        Http::fake();
        try {
            app(Delivery::class)->send('sms', '+15551234567', 'Must not send');
            $this->fail('Invalid Ghana recipient accepted');
        } catch (DeliveryException $e) {
            Http::assertNothingSent();
            $this->assertStringNotContainsString('test-only-token', $e->getMessage());
        }
    }

    public function test_registration_otp_uses_real_transport_with_mocked_provider_and_configured_sender(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['client_auth.registration_enabled' => true]);
        Http::fake(['api.txtconnect.net/*' => Http::response(['messageId' => 'fixture', 'msg' => 'success', 'data' => ['status_code' => '000', 'message' => 'success', 'in_error' => false]], 200)]);
        [$id, $secret] = app(Registration::class)->start(['name' => 'SMS test', 'organization' => 'Test organization', 'email' => 'sms-test@example.test', 'phone' => '0531005130', 'country' => 'GH', 'verification_channel' => 'phone', 'terms' => 1]);
        Http::assertSent(fn ($request) => $request['to'] === '233531005130' && $request['from'] === 'ConfiguredSender' && preg_match('/code is [0-9]{6}/', $request['sms']));
        $this->assertDatabaseHas('client_otp_logs', ['challenge_id' => $id, 'channel' => 'sms', 'status' => 'sent']);
        $this->assertNotEmpty($secret);
        $this->assertDatabaseCount('users', 0);
    }
}
