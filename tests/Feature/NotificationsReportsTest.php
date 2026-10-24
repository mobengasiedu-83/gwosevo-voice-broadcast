<?php

namespace Tests\Feature;

use App\Jobs\DeliverClientAlert;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientAccount;
use App\Models\ClientNotice;
use App\Models\Contact;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Alerts\Notifications;
use App\Services\Billing\Ledger;
use App\Services\ClientAuth\Delivery;
use App\Services\Reports\Analytics;
use App\Services\Reports\Exports;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class NotificationsReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private User $peer;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Queue::fake();
        config()->set('client_alerts.delivery_enabled', false);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        foreach (['alice', 'bob'] as $name) {
            $account = ClientAccount::create(['name' => $name, 'created_by' => $this->admin->id]);
            $this->$name = $this->client($account);
        }
        $this->peer = $this->client(ClientAccount::find($this->alice->client_account_id));
        $this->campaign = Campaign::create(['name' => '=SUM(1,2)', 'owner_id' => $this->alice->id, 'status' => 'completed']);
        Campaign::create(['name' => 'BETA PRIVATE CAMPAIGN', 'owner_id' => $this->bob->id, 'status' => 'completed']);
        $contact = Contact::create(['phone_e164' => '+233241234567', 'created_by' => $this->alice->id]);
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'contact_id' => $contact->id, 'phone_e164' => $contact->phone_e164, 'status' => 'terminal']);
        foreach ([1, 2] as $number) {
            CallAttempt::create(['campaign_recipient_id' => $recipient->id, 'uuid' => (string) Str::uuid(), 'attempt_no' => $number, 'state' => 'terminal', 'outcome' => $number === 1 ? 'busy' : 'completed', 'sent_at' => now(), 'answered_at' => $number === 2 ? now() : null, 'playback_completed_at' => $number === 2 ? now() : null, 'token' => 'PRIVATE-AUDIO-TOKEN', 'channel' => 'PRIVATE-PBX-CHANNEL', 'ended_at' => now()]);
        }
    }

    private function client(ClientAccount $account): User
    {
        $u = User::factory()->create();
        $u->forceFill(['client_account_id' => $account->id])->save();
        $u->assignRole('Client');

        return $u;
    }

    private function filters(string $kind = 'campaigns', array $extra = []): array
    {
        return array_replace(['kind' => $kind, 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'timezone' => 'Africa/Accra'], $extra);
    }

    private function wallet(): Wallet
    {
        return Wallet::create(['client_account_id' => $this->alice->client_account_id, 'currency' => 'GHS', 'minor_digits' => 3]);
    }

    public function test_account_reports_are_scoped_and_unique_recipient_funnels_do_not_count_retries_twice(): void
    {
        $this->actingAs($this->alice)->get(route('client.reports', $this->filters()))->assertOk()->assertSee('Reports and exports')->assertSee('=SUM(1,2)')->assertDontSee('BETA PRIVATE CAMPAIGN')->assertDontSee('PRIVATE-AUDIO-TOKEN')->assertDontSee('PRIVATE-PBX-CHANNEL');
        $a = app(Analytics::class);
        $summary = $a->summary($this->alice, $this->filters());
        $this->assertSame(1, (int) $summary['metrics']['audience']);
        $this->assertSame(2, (int) $summary['metrics']['attempts']);
        $this->assertSame(1, (int) $summary['metrics']['playback']);
        $calls = $a->summary($this->alice, $this->filters('attempts'));
        $this->assertSame(2, (int) $calls['metrics']['sent']);
        $this->assertSame(1, (int) $calls['metrics']['answered']);
        $this->get(route('client.reports', $this->filters('attempts')))->assertOk()->assertSee('50.0%');
        $this->get(route('client.reports', $this->filters('campaigns', ['client_account_id' => $this->bob->client_account_id])))->assertSessionHasErrors('client_account_id');
        $foreign = Campaign::where('client_account_id', $this->bob->client_account_id)->withoutGlobalScopes()->firstOrFail();
        $this->get(route('client.reports', $this->filters('campaigns', ['campaign_id' => $foreign->id])))->assertNotFound();
    }

    public function test_internal_roles_cannot_use_reporting_to_bypass_finance_or_campaign_permissions(): void
    {
        $analyst = User::factory()->create();
        $analyst->assignRole('Analyst');
        $this->actingAs($analyst)->get(route('reports.index', $this->filters()))->assertOk()->assertSee('BETA PRIVATE CAMPAIGN');
        $this->get(route('reports.index', $this->filters('billing')))->assertForbidden();
        $finance = User::factory()->create();
        $finance->assignRole('Billing Administrator');
        $this->actingAs($finance)->get('/reports')->assertOk()->assertSee('Billing ledger');
        $this->get(route('reports.index', $this->filters('attempts')))->assertForbidden();
        $this->actingAs($this->alice)->get('/reports')->assertForbidden();
    }

    public function test_csv_export_is_private_formula_safe_and_rechecks_owner_and_integrity(): void
    {
        $this->actingAs($this->alice)->post(route('client.reports.export'), $this->filters() + ['format' => 'csv'])->assertRedirect();
        $e = ReportExport::firstOrFail();
        app(Exports::class)->generate($e->id);
        $e->refresh();
        $this->assertSame('completed', $e->status);
        $content = Storage::disk('local')->get($e->storage_key);
        $this->assertStringContainsString("'=SUM(1,2)", $content);
        $this->assertStringNotContainsString('BETA PRIVATE', $content);
        $this->assertStringNotContainsString('PRIVATE-AUDIO-TOKEN', $content);
        $this->get(route('client.reports.download', $e->uuid))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->peer)->get(route('client.reports.download', $e->uuid))->assertNotFound();
        $this->actingAs($this->bob)->get(route('client.reports.download', $e->uuid))->assertNotFound();
        $this->actingAs($this->alice);
        Storage::disk('local')->put($e->storage_key, 'tampered');
        $this->get(route('client.reports.download', $e->uuid))->assertStatus(409);
        $e->update(['expires_at' => now()->subMinute()]);
        $this->get(route('client.reports.download', $e->uuid))->assertStatus(410);
        app(Exports::class)->maintain();
        Storage::disk('local')->assertMissing($e->storage_key);
    }

    public function test_xlsx_cells_are_text_for_untrusted_values_and_job_execution_is_idempotent(): void
    {
        $e = app(Exports::class)->create($this->alice, $this->filters(), 'xlsx');
        app(Exports::class)->generate($e->id);
        $e->refresh();
        $this->assertSame('completed', $e->status);
        $book = IOFactory::load(Storage::disk('local')->path($e->storage_key));
        $this->assertSame('=SUM(1,2)', $book->getActiveSheet()->getCell('B2')->getValue());
        $this->assertSame('s', $book->getActiveSheet()->getCell('B2')->getDataType());
        $book->disconnectWorksheets();
        app(Exports::class)->generate($e->id);
        $this->assertSame(1, ClientNotice::where('kind', 'report.ready')->count());
        $this->actingAs($this->peer)->get('/client/notifications')->assertDontSee('Your campaigns report is ready');
        $this->actingAs($this->alice)->get('/client/notifications')->assertSee('Your campaigns report is ready');
    }

    public function test_exports_fail_without_partial_downloads_on_row_limit_or_permission_loss(): void
    {
        config()->set('reports.max_export_rows', 1);
        $e = app(Exports::class)->create($this->alice, $this->filters('attempts'), 'csv');
        app(Exports::class)->generate($e->id);
        $this->assertSame('failed', $e->fresh()->status);
        $this->assertStringContainsString('row limit', $e->fresh()->failure_reason);
        $this->assertCount(0, Storage::disk('local')->allFiles('reports'));
        $pending = app(Exports::class)->create($this->alice, $this->filters(), 'csv');
        ClientAccount::find($this->alice->client_account_id)->update(['status' => 'suspended']);
        app(Exports::class)->generate($pending->id);
        $this->assertSame('failed', $pending->fresh()->status);
    }

    public function test_timezone_filters_and_invalid_ranges_are_applied_before_export(): void
    {
        $this->campaign->update(['created_at' => '2026-10-24 01:00:00']);
        $a = app(Analytics::class);
        $f = $this->filters('campaigns', ['from' => '2026-10-23', 'to' => '2026-10-23', 'timezone' => 'America/New_York']);
        $this->assertSame(1, $a->query($this->alice, $f)->count());
        $f['timezone'] = 'Africa/Accra';
        $this->assertSame(0, $a->query($this->alice, $f)->count());
        $this->actingAs($this->alice)->get(route('client.reports', $this->filters('campaigns', ['from' => '2020-01-01'])))->assertSessionHasErrors('to');
        $this->get(route('client.reports', $this->filters('campaigns', ['timezone' => 'invalid'])))->assertSessionHasErrors('timezone');
    }

    public function test_billing_totals_match_ledger_values_without_exposing_internal_evidence(): void
    {
        $wallet = $this->wallet();
        $entry = app(Ledger::class)->adjust($this->admin, $wallet, 1234, 'stage10-credit-001', 'PRIVATE FINANCE EVIDENCE');
        $f = $this->filters('billing');
        $s = app(Analytics::class)->summary($this->alice, $f);
        $this->assertSame(1234, (int) $s['groups'][0]->amount_minor);
        $this->actingAs($this->alice)->get(route('client.reports', $f))->assertOk()->assertSee('1.234')->assertDontSee('PRIVATE FINANCE EVIDENCE');
    }

    public function test_notification_scan_is_idempotent_and_low_credit_rearms_after_recovery(): void
    {
        $wallet = $this->wallet();
        DB::table('notification_preferences')->insert(['user_id' => $this->alice->id, 'low_balance_minor' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $n = app(Notifications::class);
        $n->scan();
        $n->scan();
        $this->assertSame(1, ClientNotice::where('kind', 'campaign.completed')->where('client_account_id', $this->alice->client_account_id)->count());
        $this->assertSame(1, ClientNotice::where('kind', 'wallet.low_credit')->count());
        $this->actingAs($this->peer)->get('/client/notifications')->assertDontSee('Low available credit');
        Auth::forgetGuards();
        app(Ledger::class)->adjust($this->admin, $wallet, 200, 'stage10-recovery', 'Verified credit');
        $n->scan();
        app(Ledger::class)->adjust($this->admin, $wallet, -150, 'stage10-lowagain', 'Verified debit');
        $n->scan();
        $this->assertSame(2, ClientNotice::where('kind', 'wallet.low_credit')->count());
        $this->actingAs($this->alice)->get('/client/notifications/count')->assertOk()->assertJsonStructure(['unread']);
        $other = ClientNotice::where('client_account_id', $this->bob->client_account_id)->withoutGlobalScopes()->firstOrFail();
        $this->post(route('client.notifications.read', $other->id))->assertNotFound();
    }

    public function test_notification_preferences_require_verified_contacts_and_are_personal(): void
    {
        $this->wallet();
        $this->actingAs($this->alice)->post(route('client.notifications.preferences'), ['sms_enabled' => 1])->assertSessionHasErrors('channels');
        $this->post(route('client.notifications.preferences'), ['email_enabled' => 1, 'low_balance' => '0.125', 'user_id' => $this->bob->id])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $this->alice->id, 'low_balance_minor' => 125, 'email_enabled' => true]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $this->bob->id]);
        $this->post(route('client.notifications.preferences'), ['low_balance' => '0.0001'])->assertSessionHasErrors('low_balance');
    }

    private function deliveryRow(): int
    {
        $n = app(Notifications::class)->notice($this->alice->client_account_id, 'delivery-fixture', 'campaign.completed', 'Campaign completed', 'PRIVATE NOTICE BODY', 'info', $this->alice->id);
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $this->alice->id], ['email_enabled' => true, 'sms_enabled' => false, 'channels_enabled_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        app(Notifications::class)->prepareDeliveries();

        return DB::table('notification_deliveries')->where('user_id', $this->alice->id)->value('id');
    }

    public function test_opt_in_delivery_is_off_by_default_and_cannot_send_duplicate_jobs(): void
    {
        $id = $this->deliveryRow();
        $transport = \Mockery::mock(Delivery::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($channel, $to, $body, $subject) => $channel === 'email' && $to === $this->alice->email && ! str_contains($body, 'PRIVATE'));
        $job = new DeliverClientAlert($id);
        $job->handle($transport);
        $this->assertSame('pending', DB::table('notification_deliveries')->find($id)->status);
        config()->set('client_alerts.delivery_enabled', true);
        $job->handle($transport);
        $job->handle($transport);
        $this->assertSame('sent', DB::table('notification_deliveries')->find($id)->status);
        $this->assertSame(1, (int) DB::table('notification_deliveries')->find($id)->attempts);
    }

    public function test_delivery_retries_are_bounded_and_suspended_accounts_never_receive_messages(): void
    {
        $id = $this->deliveryRow();
        config()->set('client_alerts.delivery_enabled', true);
        $transport = \Mockery::mock(Delivery::class);
        $transport->shouldReceive('send')->times(3)->andThrow(new \RuntimeException('PRIVATE provider response'));
        $job = new DeliverClientAlert($id);
        for ($i = 0; $i < 4; $i++) {
            DB::table('notification_deliveries')->where('id', $id)->update(['next_attempt_at' => now()->subSecond()]);
            $job->handle($transport);
        }
        $d = DB::table('notification_deliveries')->find($id);
        $this->assertSame(3, (int) $d->attempts);
        $this->assertStringNotContainsString('PRIVATE', $d->failure_reason);
        DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'pending', 'attempts' => 0]);
        ClientAccount::find($this->alice->client_account_id)->update(['status' => 'suspended']);
        $job->handle($transport);
        $this->assertSame('skipped', DB::table('notification_deliveries')->find($id)->status);
    }

    public function test_schedules_generate_once_for_a_due_slot_and_expire_private_files(): void
    {
        $service = app(Exports::class);
        $service->schedule($this->alice, $this->filters(), ['format' => 'csv', 'frequency' => 'daily', 'local_time' => '08:00', 'lookback_days' => 7]);
        $schedule = DB::table('report_schedules')->first();
        DB::table('report_schedules')->where('id', $schedule->id)->update(['next_run_at' => now()->subMinute()]);
        $service->maintain();
        $service->maintain();
        $this->assertSame(1, ReportExport::where('report_schedule_id', $schedule->id)->count());
        $e = ReportExport::firstOrFail();
        $service->generate($e->id);
        $this->assertSame('completed', $e->fresh()->status);
        $this->actingAs($this->bob)->post(route('client.reports.stop', $schedule->id))->assertNotFound();
        $this->actingAs($this->alice)->post(route('client.reports.stop', $schedule->id))->assertRedirect();
        $this->assertSame(0, (int) DB::table('report_schedules')->find($schedule->id)->active);
    }

    public function test_verified_sms_opt_in_and_hourly_throttle_are_enforced(): void
    {
        $this->alice->forceFill(['phone_e164' => '+233241234568', 'phone_verified_at' => now()])->save();
        $this->actingAs($this->alice)->post(route('client.notifications.preferences'), ['sms_enabled' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $notifications = app(Notifications::class);
        $notifications->notice($this->alice->client_account_id, 'sms-one', 'campaign.completed', 'Completed', 'Private campaign details', 'info', $this->alice->id);
        $notifications->notice($this->alice->client_account_id, 'sms-two', 'campaign.completed', 'Completed', 'Private campaign details', 'info', $this->alice->id);
        $notifications->prepareDeliveries();
        $rows = DB::table('notification_deliveries')->where('user_id', $this->alice->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        config()->set('client_alerts.delivery_enabled', true);
        config()->set('client_alerts.per_user_per_hour', 1);
        $transport = \Mockery::mock(Delivery::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($channel, $recipient, $body, $subject) => $channel === 'sms' && $recipient === '+233241234568' && ! str_contains($body, 'Private'));
        (new DeliverClientAlert($rows[0]->id))->handle($transport);
        (new DeliverClientAlert($rows[1]->id))->handle($transport);
        $this->assertSame('sent', DB::table('notification_deliveries')->find($rows[0]->id)->status);
        $this->assertSame('pending', DB::table('notification_deliveries')->find($rows[1]->id)->status);
        $this->assertNotNull(DB::table('notification_deliveries')->find($rows[1]->id)->next_attempt_at);
    }

    public function test_interrupted_sends_are_marked_uncertain_without_automatic_resending(): void
    {
        $id = $this->deliveryRow();
        DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'sending', 'attempts' => 1, 'updated_at' => now()->subMinutes(11)]);
        config()->set('client_alerts.delivery_enabled', true);
        app(Notifications::class)->prepareDeliveries();
        $this->assertSame('unknown', DB::table('notification_deliveries')->find($id)->status);
        Queue::assertNotPushed(DeliverClientAlert::class);
    }

    public function test_ivr_reports_scope_responses_and_revoked_staff_access_blocks_downloads(): void
    {
        $attempt = CallAttempt::orderBy('id')->firstOrFail();
        DB::table('dtmf_responses')->insert(['call_attempt_id' => $attempt->id, 'node_id' => 'confirm', 'digit' => '1', 'event_key' => hash('sha256', 'report-response'), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->alice)->get(route('client.reports', $this->filters('ivr')))->assertOk()->assertSee('confirm');
        $this->assertSame(1, app(Analytics::class)->summary($this->alice, $this->filters('ivr'))['metrics']['responses']);
        Auth::forgetGuards();
        $analyst = User::factory()->create();
        $analyst->assignRole('Analyst');
        $e = app(Exports::class)->create($analyst, $this->filters(), 'csv');
        app(Exports::class)->generate($e->id);
        $this->assertSame('completed', $e->fresh()->status);
        $analyst->removeRole('Analyst');
        $this->actingAs($analyst)->get(route('reports.index.download', $e->uuid))->assertForbidden();
    }
}
