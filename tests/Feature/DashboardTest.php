<?php

namespace Tests\Feature;

use App\Jobs\DashboardWorkerHeartbeat;
use App\Models\CallAttempt;
use App\Models\CallEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Dashboard\Health;
use App\Services\Dashboard\Metrics;
use App\Services\Dashboard\QueueTelemetry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function attempt(User $user, array $changes = []): CallAttempt
    {
        $campaign = Campaign::create(['name' => 'Campaign for '.$user->id, 'owner_id' => $user->id, 'status' => 'running', 'type' => 'one_way', 'purpose' => 'Updates']);
        $r = CampaignRecipient::create(['campaign_id' => $campaign->id, 'status' => 'active']);
        $uuid = (string) Str::uuid();

        return CallAttempt::create(array_replace(['uuid' => $uuid, 'uniqueid' => $uuid, 'action_id' => 'vb-'.$uuid, 'campaign_recipient_id' => $r->id, 'state' => 'active', 'sent_at' => now()->subMinute()], $changes));
    }

    public function test_direct_sign_in_lands_on_dashboard_and_preserves_intended_routes(): void
    {
        $user = $this->user('Campaign Manager');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_guest_and_role_permissions_are_enforced(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
        $manager = $this->user('Campaign Manager');
        $this->actingAs($manager)->get('/dashboard')->assertOk()->assertSee('Operations Overview');
        $this->get('/dashboard/health')->assertForbidden();
        $this->get('/dashboard/wallboard')->assertForbidden();
    }

    public function test_scoped_managers_cannot_read_other_campaign_metrics_calls_or_events(): void
    {
        $a = $this->user('Campaign Manager');
        $b = $this->user('Campaign Manager');
        $own = $this->attempt($a);
        $other = $this->attempt($b);
        $this->actingAs($a)->get('/dashboard/live')->assertOk()->assertSee($own->uuid)->assertDontSee($other->uuid);
        $this->get('/dashboard/calls/'.$other->id)->assertForbidden();
        $campaign = CampaignRecipient::findOrFail($other->campaign_recipient_id)->campaign_id;
        $this->get('/dashboard?campaign_id='.$campaign)->assertForbidden();
        $this->getJson('/dashboard/live')->assertOk()->assertJsonStructure(['html', 'generated_at'])->assertDontSee($other->uuid);
    }

    public function test_overview_metrics_use_terminal_denominator_and_exclude_unknown_from_live_count(): void
    {
        $u = $this->user('Campaign Manager');
        $this->attempt($u, ['answered_at' => now(), 'ended_at' => now(), 'state' => 'terminal', 'outcome' => 'completed']);
        $this->attempt($u, ['ended_at' => now(), 'state' => 'terminal', 'outcome' => 'busy']);
        $this->attempt($u, ['state' => 'unknown', 'outcome' => 'reconciling']);
        $this->attempt($u);
        $metrics = app(Metrics::class)->overview($u, ['date' => now()->toDateString(), 'timezone' => 'UTC']);
        $this->assertSame(4, $metrics['calls_today']);
        $this->assertSame(2, $metrics['terminal']);
        $this->assertSame(50.0, $metrics['answer_rate']);
        $this->assertSame(1, $metrics['active_calls']);
        $this->assertSame(1, $metrics['uncertain']);
        $this->assertSame(4, array_sum(array_column($metrics['hours'], 'count')));
    }

    public function test_selected_timezone_controls_date_boundaries(): void
    {
        $u = $this->user('Campaign Manager');
        $this->attempt($u, ['sent_at' => '2026-10-23 22:30:00']);
        $metrics = app(Metrics::class)->overview($u, ['date' => '2026-10-24', 'timezone' => 'Africa/Nairobi']);
        $this->assertSame(1, $metrics['calls_today']);
        $this->assertSame(1, $metrics['hours'][1]['count']);
        $this->assertSame(0, app(Metrics::class)->overview($u, ['date' => '2026-10-24', 'timezone' => 'UTC'])['calls_today']);
    }

    public function test_empty_metrics_do_not_invent_answer_rate(): void
    {
        $this->actingAs($this->user('Super Administrator'))->get('/dashboard')->assertOk()->assertSee('No calls submitted in this period')->assertSee('0 answered / 0 terminal');
    }

    public function test_stale_queue_snapshot_never_claims_live_ready_counts(): void
    {
        $telemetry = app(QueueTelemetry::class);
        $telemetry->snapshot([
            ['Event' => 'QueueParams', 'Queue' => '200', 'Calls' => '2', 'Completed' => '5', 'Abandoned' => '1', 'ServicelevelPerf' => '75'],
            ['Event' => 'QueueMember', 'Queue' => '200', 'Location' => 'PJSIP/200', 'Name' => 'Operator One', 'Status' => '1', 'Paused' => '0', 'InCall' => '0'],
        ]);
        $board = app(Health::class)->wallboard([]);
        $this->assertTrue($board['fresh']);
        $this->assertSame(1, $board['totals']['ready']);
        DB::table('dashboard_telemetry')->where('source', 'queues')->update(['observed_at' => now()->subMinutes(2)]);
        $this->actingAs($this->user('Operator'))->get('/dashboard/wallboard')->assertOk()->assertSee('historical')->assertSee('Stale');
    }

    public function test_queue_updates_preserve_member_name_and_show_reported_wrapup(): void
    {
        $t = app(QueueTelemetry::class);
        $t->snapshot([['Event' => 'QueueMember', 'Queue' => '200', 'Location' => 'PJSIP/200', 'Name' => 'Operator One', 'Status' => '1', 'Paused' => '0']]);
        $t->event(['Event' => 'QueueMemberStatus', 'Queue' => '200', 'Interface' => 'PJSIP/200', 'Status' => '1', 'LastCall' => now()->timestamp, 'Wrapuptime' => '30', 'InCall' => '0']);
        $board = app(Health::class)->wallboard([]);
        $this->assertSame('Operator One', $board['members'][0]->name);
        $this->assertSame('wrapup', $board['members'][0]->display_state);
        $t->event(['Event' => 'QueueMemberRemoved', 'Queue' => '200', 'Interface' => 'PJSIP/200']);
        $this->assertDatabaseCount('dashboard_queue_members', 0);
    }

    public function test_health_and_wallboard_pages_render_with_missing_probes(): void
    {
        $this->actingAs($this->user('Telephony Administrator'))->get('/dashboard/health')->assertOk()->assertSee('Not probed')->assertDontSee('VITALPBX_AMI_SECRET');
        $this->get('/dashboard/wallboard')->assertOk()->assertSee('Waiting for queue telemetry');
    }

    public function test_stop_requests_are_authorized_audited_and_do_not_claim_hangup(): void
    {
        $admin = $this->user('Super Administrator');
        $a = $this->attempt($admin);
        $this->actingAs($this->user('Operator'))->post('/dashboard/calls/'.$a->id.'/stop', ['reason' => 'Test'])->assertForbidden();
        $this->actingAs($admin)->post('/dashboard/calls/'.$a->id.'/stop', ['reason' => 'Requested by operator'])->assertRedirect();
        $this->assertNotNull($a->fresh()->stop_requested_at);
        $this->assertNull($a->fresh()->ended_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call.stop_requested', 'entity_id' => $a->id]);
        $a->update(['ended_at' => now()]);
        $this->post('/dashboard/calls/'.$a->id.'/stop', ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_call_detail_masks_raw_event_payloads_and_secrets(): void
    {
        $u = $this->user('Campaign Manager');
        $a = $this->attempt($u);
        CallEvent::create(['call_attempt_id' => $a->id, 'event_key' => hash('sha256', 'test'), 'event_type' => 'Newstate', 'payload' => ['secret' => 'never-display'], 'occurred_at' => now()]);
        $this->actingAs($u)->get('/dashboard/calls/'.$a->id)->assertOk()->assertSee('Newstate')->assertDontSee('never-display');
    }

    public function test_worker_heartbeat_records_measured_queue_delay(): void
    {
        (new DashboardWorkerHeartbeat(microtime(true) - 3))->handle(app(Health::class));
        $probe = DB::table('dashboard_telemetry')->where('source', 'worker')->first();
        $this->assertSame('healthy', $probe->status);
        $this->assertGreaterThanOrEqual(3, json_decode($probe->payload, true)['lag_seconds']);
    }

    public function test_filters_are_validated_and_html_is_escaped(): void
    {
        $u = $this->user('Campaign Manager');
        $this->attempt($u);
        Campaign::where('owner_id', $u->id)->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($u)->get('/dashboard/live')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson('/dashboard?timezone=bad-zone')->assertUnprocessable();
        $this->getJson('/dashboard/live?state=invalid')->assertUnprocessable();
    }
}
