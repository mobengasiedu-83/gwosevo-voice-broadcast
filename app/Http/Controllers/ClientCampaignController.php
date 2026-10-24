<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\Billing\Ledger;
use App\Services\Broadcast\Readiness;
use App\Services\Campaigns\Workspace;
use App\Services\Clients\Campaigns;
use App\Services\Clients\Portal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientCampaignController extends Controller
{
    private function shared(Request $request): array
    {
        $portal = app(Portal::class);
        $portal->authorize($request->user());

        return ['account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => $portal->unread($request->user())];
    }

    public function edit(Request $request, Campaigns $service, ?Campaign $campaign = null)
    {
        $shared = $this->shared($request);
        if ($campaign) {
            $service->access($request->user(), $campaign, true);
            abort_unless(! $campaign->archived_at && in_array($campaign->status, ['draft', 'ready'], true), 422);
        }

        return view('clients.campaign-edit', $shared + [
            'campaign' => $campaign,
            'groups' => ContactGroup::where('client_account_id', $request->user()->client_account_id)->where('active', true)->withCount('contacts')->orderBy('name')->get(),
            'audio' => AudioFile::where('client_account_id', $request->user()->client_account_id)->where('status', 'approved')->orderBy('name')->get(['id', 'name', 'duration']),
        ]);
    }

    public function save(Request $request, Campaigns $service, ?Campaign $campaign = null)
    {
        $this->shared($request);
        if ($campaign) {
            $service->access($request->user(), $campaign, true);
        }
        $data = $request->validate([
            'name' => 'required|string|max:240', 'purpose' => 'required|string|max:255',
            'source_group_id' => 'required|integer', 'audio_id' => 'required|integer', 'timezone' => 'required|timezone',
            'scheduled_at' => 'nullable|date_format:Y-m-d\TH:i',
            'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i|after:window_start',
            'max_attempts' => 'required|integer|min:1|max:5', 'retry_delay_seconds' => 'required|integer|min:60|max:86400',
        ]);
        $data += $request->only(['retry_outcomes', 'retry_policy_configured', 'excluded_dates_text', 'expires_on']);
        if (! empty($data['scheduled_at']) && ! CarbonImmutable::parse($data['scheduled_at'], $data['timezone'])->isFuture()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a future time in the selected timezone.']);
        }
        $saved = $service->save($request->user(), $campaign, $data);

        return redirect()->route('client.campaigns.show', $saved)->with('status', 'Campaign saved. Review the audience, recording and launch checks before starting.');
    }

    public function show(Request $request, Campaign $campaign, Campaigns $service, Workspace $workspace)
    {
        $shared = $this->shared($request);
        $service->access($request->user(), $campaign);
        $technicalReady = true;
        try {
            app(Readiness::class)->snapshot($campaign);
        } catch (ValidationException|ModelNotFoundException $e) {
            $technicalReady = false;
        }
        $billingPreview = null;
        $billingIssue = null;
        try {
            $billingPreview = $campaign->active_version_id
                ? $campaign->versions()->whereKey($campaign->active_version_id)->first()?->snapshot['broadcast']['billing'] ?? null
                : app(Ledger::class)->quote($campaign);
        } catch (ValidationException $e) {
            $billingIssue = 'A configured price and sufficient available credit are required. Review your wallet or contact support.';
        }
        $attempts = CallAttempt::whereIn('campaign_recipient_id', CampaignRecipient::where('campaign_id', $campaign->id)->select('id'));

        return view('clients.campaign-show', $shared + [
            'billingPreview' => $billingPreview, 'billingIssue' => $billingIssue,
            'campaign' => $campaign, 'technicalReady' => $technicalReady, 'eligibility' => $workspace->eligibility($campaign),
            'selectedAudio' => AudioFile::find($campaign->configuration['audio_id'] ?? null),
            'group' => ContactGroup::find($campaign->configuration['source_group_id'] ?? null),
            'mayManage' => (int) $campaign->owner_id === (int) $request->user()->id,
            'executionEnabled' => (bool) config('voice_broadcast.client_execution_enabled'),
            'attempts' => (clone $attempts)->latest('id')->paginate(25, ['id', 'attempt_no', 'state', 'outcome', 'answered_at', 'playback_started_at', 'playback_completed_at', 'ended_at']),
            'totals' => ['attempts' => (clone $attempts)->count(), 'answered' => (clone $attempts)->whereNotNull('answered_at')->count(), 'playback' => (clone $attempts)->whereNotNull('playback_completed_at')->count()],
            'tests' => Campaign::where('source_campaign_id', $campaign->id)->where('configuration->client_test', true)->latest()->limit(10)->get(['id', 'name', 'status']),
            'testContacts' => Contact::where('client_account_id', $request->user()->client_account_id)->whereIn('phone_e164', config('voice_broadcast.client_test_numbers', []))->get(['id', 'name', 'phone_e164']),
        ]);
    }

    public function control(Request $request, Campaign $campaign, Campaigns $service)
    {
        $data = $request->validate(['action' => 'required|in:start,pause,resume,cancel', 'reason' => 'required|string|max:1000']);
        try {
            $service->control($request->user(), $campaign, $data['action'], $data['reason']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['campaign' => 'The action could not pass current launch or state checks. Review this campaign and contact support if needed.']);
        }

        return redirect()->route('client.campaigns.show', $campaign)->with('status', 'Control recorded. Pausing stops new dialing; call termination requires gateway confirmation.');
    }

    public function test(Request $request, Campaign $campaign, Campaigns $service)
    {
        $data = $request->validate(['contact_id' => 'required|integer']);
        try {
            $test = $service->test($request->user(), $campaign, $data['contact_id']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['campaign' => 'The test could not pass launch checks, or an earlier test is still active. Review the campaign and contact support if needed.']);
        }

        return redirect()->route('client.campaigns.show', $test)->with('status', 'One test recipient queued through the normal launch and dispatch checks. Confirm audible playback with the recipient.');
    }
}
