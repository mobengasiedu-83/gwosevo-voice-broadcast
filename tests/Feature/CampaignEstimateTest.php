<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Client;
use App\Models\ContactGroup;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Campaigns\Workspace;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CampaignEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Client $client;

    private ContactGroup $group;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Forecast account');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');

        $this->group = ContactGroup::create(['client_id' => $this->client->id, 'owner_id' => $this->member->id, 'name' => 'Forecast group']);
        foreach (['0241234501', '0241234502', '0241234503'] as $index => $phone) {
            $contact = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->member, ['name' => 'Contact '.$index, 'phone' => $phone, 'source' => 'Form']));
            $this->group->contacts()->attach($contact);
        }

        $this->audio = app(Tenancy::class)->run($this->client->id, fn () => AudioFile::create([
            'client_id' => $this->client->id, 'uuid' => (string) Str::uuid(), 'name' => 'Update',
            'storage_key' => 'audio/update.wav', 'checksum' => str_repeat('a', 64), 'duration' => 45,
            'status' => 'approved', 'uploaded_by' => $this->member->id,
        ]));
    }

    private function tariff(int $rate = 30): void
    {
        $tariff = app(Tariffs::class)->create($this->admin, [
            'code' => 'forecast-test', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2,
            'rate_minor_per_minute' => $rate, 'playback_policy' => 'charge_answered',
        ], 'Test-only approved fixture');
        app(Tariffs::class)->assign($this->admin, $this->client, $tariff, 'Test-only assignment');
    }

    private function wallet(string $amount = '100.00'): WalletAccount
    {
        $wallet = app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Forecast test');
        app(Wallet::class)->manualCredit($this->admin, $wallet, $amount, 'Forecast test', 'TEST-FORECAST');

        return $wallet;
    }

    private function estimate(array $params = []): TestResponse
    {
        return $this->actingAs($this->member)->getJson(route('campaigns.estimate', array_merge([
            'source_group_id' => $this->group->id,
            'audio_id' => $this->audio->id,
            'type' => 'one_way',
        ], $params)));
    }

    public function test_audio_budget_does_not_imply_enough_credit_for_admission(): void
    {
        config()->set('billing.max_call_seconds', 900);
        $this->tariff();
        $this->wallet('1.00');
        $this->estimate()->assertOk()->assertJsonPath('estimate.affordable', true)
            ->assertJsonPath('estimate.can_start_call', false)
            ->assertJsonPath('estimate.call_hold_minor', 450)
            ->assertJsonPath('estimate.admission_reason', 'insufficient_credit')
            ->assertSee('New calls cannot start');
    }

    public function test_it_prices_one_attempt_for_each_eligible_contact(): void
    {
        $this->tariff();
        $this->wallet();

        $this->estimate()
            ->assertOk()
            ->assertJsonPath('estimate.group_total', 3)
            ->assertJsonPath('estimate.audience_total', 3)
            ->assertJsonPath('estimate.eligible', 3)
            ->assertJsonPath('estimate.duration_label', '0:45')
            ->assertJsonPath('estimate.minutes_per_call', 1)
            ->assertJsonPath('estimate.per_call_minor', 30)
            ->assertJsonPath('estimate.total_minor', 90)
            ->assertJsonPath('estimate.available_minor', 10000)
            ->assertJsonPath('estimate.additional_minor', 0)
            ->assertJsonPath('estimate.covered_calls', 333)
            ->assertJsonPath('estimate.affordable', true);
    }

    public function test_it_reports_the_top_up_needed_for_the_whole_audience(): void
    {
        $this->tariff();
        $this->wallet('0.50');

        $this->estimate()
            ->assertOk()
            ->assertJsonPath('estimate.total_minor', 90)
            ->assertJsonPath('estimate.available_minor', 50)
            ->assertJsonPath('estimate.additional_minor', 40)
            ->assertJsonPath('estimate.covered_calls', 1)
            ->assertJsonPath('estimate.affordable', false)
            ->assertJsonPath('estimate.messages.0.tone', 'warning')
            ->assertJsonPath('estimate.messages.0.text', 'Available credit covers 1 of 3 one-attempt calls. Add 0.40 GHS to call every eligible contact once.');
    }

    public function test_it_rounds_each_answered_call_up_to_whole_minutes(): void
    {
        $this->tariff();
        $this->wallet();

        foreach ([59 => 30, 60 => 30, 61 => 60, 121 => 90] as $seconds => $perCall) {
            $this->audio->update(['duration' => $seconds]);
            $this->estimate()
                ->assertOk()
                ->assertJsonPath('estimate.minutes_per_call', intdiv($perCall, 30))
                ->assertJsonPath('estimate.per_call_minor', $perCall)
                ->assertJsonPath('estimate.total_minor', $perCall * 3);
        }
    }

    public function test_it_excludes_dnd_contacts_and_reports_consent_coverage(): void
    {
        $this->tariff();
        $this->wallet();

        $contacts = $this->group->contacts()->orderBy('contacts.id')->get();
        $this->actingAs($this->member)->post('/contacts/'.$contacts[0]->id.'/suppress', ['reason' => 'Opted out'])->assertRedirect();
        foreach ($contacts->take(2) as $contact) {
            $contact->consents()->create([
                'purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form',
                'evidence_ref' => 'Form-'.$contact->id, 'granted_at' => now()->subDay(),
            ]);
        }
        $contacts[1]->consents()->firstOrFail()->update(['revoked_at' => now()]);

        $this->estimate()
            ->assertOk()
            ->assertJsonPath('estimate.audience_total', 3)
            ->assertJsonPath('estimate.eligible', 2)
            ->assertJsonPath('estimate.suppressed', 1)
            ->assertJsonPath('estimate.consent_on_record', 1)
            ->assertJsonPath('estimate.total_minor', 60);
    }

    public function test_it_does_not_forecast_another_accounts_audience(): void
    {
        $this->tariff();
        $this->wallet();

        $accounts = app(Accounts::class);
        $other = $accounts->create($this->admin, 'Foreign account');
        $accounts->status($this->admin, $other, 'active', 'Ready');
        [$foreignGroup, $foreignContact] = app(Tenancy::class)->run($other->id, function () use ($other) {
            $group = ContactGroup::create(['client_id' => $other->id, 'owner_id' => $this->member->id, 'name' => 'Foreign group']);
            $contact = app(Foundation::class)->createContact($this->member, ['name' => 'Foreign', 'phone' => '0551234599', 'source' => 'Form']);
            $group->contacts()->attach($contact);

            return [$group, $contact];
        });

        $this->estimate(['source_group_id' => $foreignGroup->id, 'audio_id' => null])
            ->assertOk()
            ->assertJsonPath('estimate.audience_total', 0)
            ->assertJsonPath('estimate.reason', 'no_audience');

        $this->estimate(['source_group_id' => null, 'contact_ids' => (string) $foreignContact->id])
            ->assertOk()
            ->assertJsonPath('estimate.audience_total', 0);

        $this->estimate()->assertOk()->assertJsonPath('estimate.audience_total', 3);
    }

    public function test_it_explains_missing_audio_wallet_and_tariff(): void
    {
        $this->estimate(['audio_id' => null])
            ->assertOk()
            ->assertJsonPath('estimate.reason', 'audio_missing')
            ->assertJsonPath('estimate.total_minor', null);

        $this->estimate()->assertOk()->assertJsonPath('estimate.reason', 'wallet_missing');

        $this->wallet();
        $this->estimate()->assertOk()->assertJsonPath('estimate.reason', 'tariff_missing')->assertJsonPath('estimate.additional_minor', null);
    }

    public function test_internal_campaigns_are_not_wallet_billed(): void
    {
        $this->actingAs($this->admin)->getJson(route('campaigns.estimate', [
            'source_group_id' => $this->group->id, 'audio_id' => $this->audio->id, 'type' => 'one_way',
        ]))
            ->assertOk()
            ->assertJsonPath('estimate.billing_applicable', false)
            ->assertJsonPath('estimate.reason', 'internal_campaign')
            ->assertJsonPath('estimate.available_minor', null)
            ->assertJsonPath('estimate.additional_minor', null);
    }

    public function test_create_page_renders_the_forecast_cards(): void
    {
        $this->tariff();
        $this->wallet();

        $this->actingAs($this->member)->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Credit forecast')
            ->assertSee('Contacts in group')
            ->assertSee('Eligible contacts after consent/suppression checks')
            ->assertSee('Recording duration')
            ->assertSee('Estimated credit for one attempt each')
            ->assertSee('Available credit')
            ->assertSee('Estimated additional credit needed')
            ->assertSee('campaign-estimate.js', false);
    }

    public function test_edit_page_preloads_the_saved_audience_forecast(): void
    {
        $this->tariff();
        $this->wallet();
        $this->actingAs($this->member);

        $campaign = app(Workspace::class)->save($this->member, null, [
            'name' => 'Forecast campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'dialing_strategy' => 'direct',
            'timezone' => 'Africa/Accra', 'audio_id' => $this->audio->id, 'source_group_id' => $this->group->id,
            'contact_ids' => '', 'scheduled_at' => null, 'window_start' => '08:00', 'window_end' => '18:00',
            'concurrency' => 2, 'cps' => 1, 'max_attempts' => 1, 'retry_delay_seconds' => 300, 'priority' => 5,
            'ivr_flow_id' => null, 'caller_id' => null,
        ]);

        $this->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('0:45')
            ->assertSee('0.90')
            ->assertSee('Balance covers all 3 calls');
    }

    public function test_it_requires_campaign_management_permission(): void
    {
        $auditor = User::factory()->create();
        $auditor->assignRole('Auditor');

        $this->actingAs($auditor)->getJson(route('campaigns.estimate'))->assertForbidden();
    }
}
