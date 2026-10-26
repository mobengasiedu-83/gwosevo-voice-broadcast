<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Mail\AccountAlert;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Billing\Wallet;
use App\Services\Broadcast\Engine;
use App\Services\Clients\Accounts;
use App\Services\Foundation;
use App\Services\Identity\Delivery as SmsDelivery;
use App\Services\Notifications\Center;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $member;

    private User $colleague;

    private Client $client;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        config()->set('notifications.low_balance_minor', 500);
        config()->set('notifications.low_balance_cooldown_minutes', 1440);
        Queue::fake();
        Mail::fake();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Alert customer');
        $this->other = $accounts->create($this->admin, 'Foreign customer');
        foreach ([$this->client, $this->other] as $client) {
            $accounts->status($this->admin, $client, 'active', 'Ready');
        }
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->colleague = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $this->client, $this->member);
        $accounts->member($this->admin, $this->client, $this->colleague);
    }

    private function campaign(string $status = 'pending_approval', ?User $owner = null): Campaign
    {
        return Campaign::create([
            'client_id' => $this->client->id,
            'owner_id' => ($owner ?? $this->member)->id,
            'name' => 'October launch',
            'purpose' => 'Updates',
            'type' => 'one_way',
            'status' => $status,
        ]);
    }

    public function test_campaign_review_creates_personal_alerts_tracked_delivery_and_read_state(): void
    {
        $campaign = $this->campaign();

        app(Foundation::class)->transition($this->admin, $campaign, 'approve', 'Verified audience');

        $ownerAlert = UserNotification::where('user_id', $this->owner->id)->sole();
        $memberAlert = UserNotification::where('user_id', $this->member->id)->sole();
        $this->assertSame('campaign', $memberAlert->category);
        $this->assertSame('campaign.review.approve', $memberAlert->event_key);
        $this->assertSame('Campaign approved', $memberAlert->title);
        $this->assertTrue($memberAlert->in_app);
        $this->assertStringContainsString('“October launch” was approved', (string) $memberAlert->body);
        $this->assertSame($campaign->id, $memberAlert->data['campaign_id']);
        $this->assertSame(0, UserNotification::where('user_id', $this->colleague->id)->count());

        // Both active owners receive a copy; the non-owner colleague does not.
        $this->assertSame(1, UserNotification::where('user_id', $this->owner->id)->count());
        $this->assertSame('queued', $memberAlert->deliveries()->where('channel', 'email')->sole()->status);
        $this->assertSame('skipped', $memberAlert->deliveries()->where('channel', 'sms')->sole()->status);

        $this->actingAs($this->member)->get(route('account.notifications'))
            ->assertOk()->assertSee('Campaign approved')->assertSee('October launch');
        $this->actingAs($this->colleague)->get(route('account.notifications'))
            ->assertOk()->assertDontSee('was approved and is ready for launch');
        $this->actingAs($this->admin)->get(route('notifications.index'))
            ->assertOk()->assertSee('Account alerts')->assertDontSee('was approved and is ready for launch');

        $this->actingAs($this->member)
            ->post(route('account.notifications.alerts.read'), ['through_id' => $memberAlert->id])
            ->assertRedirect();
        $this->assertNotNull($memberAlert->fresh()->read_at);
        $this->assertNull($ownerAlert->fresh()->read_at);

        // A colleague cannot mark another user's personal alert as read.
        $this->actingAs($this->colleague)
            ->post(route('account.notifications.alerts.read'), ['through_id' => $memberAlert->id])
            ->assertNotFound();

        $this->actingAs($this->member)->get(route('account.notifications', ['category' => 'payment']))
            ->assertOk()->assertSee('No account alerts in this category.');
        $this->actingAs($this->member)->get(route('account.notifications', ['category' => 'forged']))
            ->assertSessionHasErrors('category');
    }

    public function test_email_and_sms_delivery_jobs_record_status_and_respect_channel_transports(): void
    {
        $notification = app(Center::class)->notifyUser($this->member, [
            'client_id' => $this->client->id,
            'category' => 'payment',
            'event_key' => 'payment.succeeded',
            'severity' => 'success',
            'title' => 'Top-up confirmed',
            'body' => 'Your top-up was added to calling credit.',
            'url' => route('wallet.index'),
        ]);
        $this->assertNotNull($notification);

        $email = $notification->deliveries()->where('channel', 'email')->sole();
        $this->assertSame('queued', $email->status);
        (new DeliverNotification($email->id))->handle(app(SmsDelivery::class));
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(1, $email->fresh()->attempts);
        $this->assertNotNull($email->fresh()->sent_at);
        Mail::assertSent(AccountAlert::class, fn (AccountAlert $mail) => $mail->hasTo($this->member->email)
            && $mail->alertTitle === 'Top-up confirmed');

        config()->set('client_auth.sms_driver', 'txtconnect');
        config()->set('client_auth.sms_token', 'test-token');
        config()->set('client_auth.sms_sender', 'GWO');
        config()->set('client_auth.sms_url', 'https://sms.example.test/send');
        Http::fake(['sms.example.test/*' => Http::response([
            'data' => ['status_code' => '000', 'in_error' => false, 'reason' => 'Accepted'],
            'messageId' => 'sms-123',
        ])]);
        $smsUser = User::factory()->create();
        $smsUser->forceFill(['phone_e164' => '+233241000111'])->save();
        $smsAlert = app(Center::class)->notifyUser($smsUser, [
            'client_id' => $this->client->id,
            'category' => 'billing',
            'event_key' => 'billing.test',
            'title' => 'Low calling credit',
            'body' => 'Please top up before launching.',
            'channels' => ['sms'],
        ]);
        $sms = $smsAlert->deliveries()->where('channel', 'sms')->sole();
        $this->assertSame('queued', $sms->status);
        (new DeliverNotification($sms->id))->handle(app(SmsDelivery::class));
        $this->assertSame('sent', $sms->fresh()->status);
        $this->assertSame('sms-123', $sms->fresh()->provider_message_id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sms.example.test/send'
            && $request['to'] === '233241000111'
            && str_contains($request['sms'], 'Low calling credit'));
    }

    public function test_low_balance_alerts_are_deduplicated_per_wallet_and_cooldown_window(): void
    {
        $wallet = app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Approved onboarding');
        $notifier = app(Notifier::class);

        $notifier->lowBalance($wallet, 450);
        $notifier->lowBalance($wallet->fresh(), 450);

        $this->assertSame(1, UserNotification::where('user_id', $this->member->id)
            ->where('event_key', 'billing.low_balance')->count());
        $alert = UserNotification::where('user_id', $this->member->id)->sole();
        $this->assertSame('billing', $alert->category);
        $this->assertSame('Low calling credit', $alert->title);
        $this->assertTrue($alert->in_app);
        $this->assertStringContainsString('below the 5.00 GHS alert threshold', (string) $alert->body);

        // A later window is a new alert; the same window never duplicates it.
        $this->travelTo(CarbonImmutable::parse('2026-10-27T13:00:00.000000Z'));
        $notifier->lowBalance($wallet->fresh(), 450);
        $this->assertSame(2, UserNotification::where('user_id', $this->member->id)
            ->where('event_key', 'billing.low_balance')->count());
    }

    public function test_campaign_completion_alerts_the_account_owners_once(): void
    {
        $campaign = $this->campaign('running');

        app(Engine::class)->complete($campaign->id);

        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->member->id,
            'event_key' => 'campaign.completed',
            'category' => 'campaign',
        ]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->owner->id, 'event_key' => 'campaign.completed']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $this->colleague->id, 'event_key' => 'campaign.completed']);
        $this->assertSame(2, UserNotification::where('event_key', 'campaign.completed')->count());
    }

    public function test_notification_creation_is_user_scoped_and_disabled_globally_when_requested(): void
    {
        $center = app(Center::class);
        $alert = $center->notifyUser($this->colleague, [
            'client_id' => $this->client->id,
            'category' => 'review',
            'event_key' => 'review.test',
            'title' => 'Colleague-only alert',
            'body' => 'Only the intended member can read this.',
        ]);
        $this->assertNotNull($alert);

        $this->actingAs($this->member)->get(route('notifications.index'))
            ->assertOk()->assertDontSee('Colleague-only alert');
        $this->actingAs($this->colleague)->get(route('notifications.index'))
            ->assertOk()->assertSee('Colleague-only alert');

        config()->set('notifications.enabled', false);
        $this->assertNull($center->notifyUser($this->member, [
            'client_id' => $this->client->id,
            'category' => 'billing',
            'event_key' => 'disabled.test',
            'title' => 'Must not exist',
        ]));
        $this->assertDatabaseMissing('user_notifications', ['event_key' => 'disabled.test']);
    }

    public function test_mark_through_only_reads_alerts_up_to_the_visible_id(): void
    {
        $center = app(Center::class);
        foreach (['First alert', 'Second alert', 'Third alert'] as $index => $title) {
            $center->notifyUser($this->member, [
                'client_id' => $this->client->id,
                'category' => 'campaign',
                'event_key' => 'campaign.test.'.$index,
                'title' => $title,
            ]);
        }
        $alerts = UserNotification::where('user_id', $this->member->id)->orderBy('id')->get();

        $this->actingAs($this->member)
            ->post(route('notifications.read'), ['through_id' => $alerts[1]->id])
            ->assertRedirect();
        $this->assertNotNull($alerts[0]->fresh()->read_at);
        $this->assertNotNull($alerts[1]->fresh()->read_at);
        $this->assertNull($alerts[2]->fresh()->read_at);
        $this->assertSame(1, app(Center::class)->summary($this->member)['unread']);

        $this->actingAs($this->member)
            ->post(route('notifications.read'), ['through_id' => $alerts[2]->id])
            ->assertRedirect();
        $this->assertSame(0, app(Center::class)->summary($this->member)['unread']);
    }
}
