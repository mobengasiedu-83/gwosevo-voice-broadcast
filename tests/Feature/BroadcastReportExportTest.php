<?php

namespace Tests\Feature;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\Accounts;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BroadcastReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $foreign;

    private Client $client;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Export customer');
        $other = $accounts->create($this->admin, 'Foreign customer');
        foreach ([$this->client, $other] as $client) {
            $accounts->status($this->admin, $client, 'active', 'Ready');
        }
        $this->owner = User::factory()->create();
        $this->foreign = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $other, $this->foreign, 'owner');
        $this->campaign = Campaign::create([
            'client_id' => $this->client->id,
            'owner_id' => $this->owner->id,
            'name' => 'October launch',
            'purpose' => 'Updates',
            'type' => 'one_way',
            'status' => 'completed',
        ]);
    }

    private function attempt(string $outcome): CallAttempt
    {
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'terminal']);
        $uuid = (string) Str::uuid();

        return CallAttempt::create([
            'uuid' => $uuid,
            'uniqueid' => $uuid,
            'action_id' => 'vb-'.$uuid,
            'linkedid' => 'linked-'.$uuid,
            'campaign_recipient_id' => $recipient->id,
            'attempt_no' => 1,
            'state' => 'terminal',
            'outcome' => $outcome,
            'sent_at' => CarbonImmutable::now()->subSeconds(30),
            'answered_at' => CarbonImmutable::now()->subSeconds(25),
            'ended_at' => CarbonImmutable::now()->subSeconds(20),
        ]);
    }

    public function test_campaign_report_exports_a_streamed_csv_with_aligned_escaped_cells(): void
    {
        $outcomes = ['=2+2', '+SUM(1,1)', '-1+1', '@cmd'];
        $attempts = array_map(fn (string $outcome) => $this->attempt($outcome), $outcomes);

        $response = $this->actingAs($this->owner)
            ->get(route('broadcast.report.export', $this->campaign))
            ->assertOk()
            ->assertDownload('campaign-october-launch-'.$this->campaign->id.'-report.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), $lines);
        $this->assertCount(5, $rows);
        $this->assertCount(13, $rows[0]);
        $this->assertSame(
            'attempt_uuid,state,outcome,sent_at,answered_at,playback_started_at,playback_completed_at,ended_at,duration_ms,billable_minutes,rated_amount,currency,billing_exceptions',
            $lines[0],
        );
        $this->assertStringNotContainsString('rating_currency', $lines[0]);

        foreach (array_values($outcomes) as $index => $outcome) {
            $row = $rows[$index + 1];
            $this->assertCount(count($rows[0]), $row);
            $this->assertSame($attempts[$index]->uuid, $row[0]);
            $this->assertSame("'".$outcome, $row[2]);
        }
    }

    public function test_report_export_is_tenant_scoped_and_requires_authentication(): void
    {
        $this->get(route('broadcast.report.export', $this->campaign))->assertRedirect(route('login'));
        $this->actingAs($this->foreign)
            ->get(route('broadcast.report.export', $this->campaign))
            ->assertNotFound();
    }

    public function test_empty_campaign_export_still_contains_the_documented_header(): void
    {
        $csv = $this->actingAs($this->admin)
            ->get(route('broadcast.report.export', $this->campaign))
            ->assertOk()->streamedContent();

        $this->assertSame(
            'attempt_uuid,state,outcome,sent_at,answered_at,playback_started_at,playback_completed_at,ended_at,duration_ms,billable_minutes,rated_amount,currency,billing_exceptions',
            trim($csv),
        );
    }
}
