<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing\Wallet;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WalletCleanupScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_cleanup_is_registered_with_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'billing:wallet-expire --scheduled'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_disabled_policy_does_not_release_credit(): void
    {
        config()->set('billing.hold_cleanup.enabled', false);
        $this->mock(Wallet::class)->shouldNotReceive('expire');
        $this->artisan('billing:wallet-expire --scheduled')->expectsOutputToContain('disabled')->assertSuccessful();
    }

    public function test_enabled_policy_rejects_missing_or_unauthorized_actor(): void
    {
        config()->set('billing.hold_cleanup.enabled', true);
        config()->set('billing.hold_cleanup.actor_id', 0);
        Log::spy();
        $this->mock(Wallet::class)->shouldNotReceive('expire');
        $this->artisan('billing:wallet-expire --scheduled')->assertFailed();
        Log::shouldHaveReceived('error')->with('billing.hold_cleanup_invalid_actor', ['scheduled' => true])->once();
    }

    public function test_enabled_policy_uses_configured_actor_age_and_batch_and_logs_results(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        config()->set('billing.hold_cleanup', ['enabled' => true, 'actor_id' => $admin->id, 'hours' => 48, 'limit' => 100]);
        Log::spy();
        $this->mock(Wallet::class)->shouldReceive('expire')->once()->withArgs(fn ($hours, $limit, $actor) => $hours === 48 && $limit === 100 && $actor->id === $admin->id)->andReturn(3);
        $this->artisan('billing:wallet-expire --scheduled')->expectsOutputToContain('Released 3')->assertSuccessful();
        Log::shouldHaveReceived('info')->with('billing.hold_cleanup_completed', \Mockery::on(fn ($data) => $data['released'] === 3 && $data['remaining_stale'] === 0))->once();
    }
}
