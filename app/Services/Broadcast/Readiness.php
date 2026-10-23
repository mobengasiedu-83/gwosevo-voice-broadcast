<?php

namespace App\Services\Broadcast;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\IvrFlow;
use App\Models\TrunkProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Readiness
{
    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['broadcast' => $message]);
    }

    public function audio(AudioFile $audio): void
    {
        if ($audio->status !== 'approved') {
            $this->fail('The selected audio must be approved in the Audio Library.');
        }
        if (! $audio->checksum || ! Storage::disk('local')->exists($audio->storage_key ?? '') || ! hash_equals($audio->checksum, hash_file('sha256', Storage::disk('local')->path($audio->storage_key)))) {
            $this->fail('The audio file is missing or its checksum has changed. Upload and approve a new recording.');
        }
        if (! $audio->deployed_at) {
            $this->fail('Audio is approved, but PBX installation has not been recorded. Open the Audio Library, install the WAV on the PBX, then record its verified installation.');
        }
    }

    public function trunk(TrunkProfile $trunk): void
    {
        if (! $trunk->active || ! $trunk->verified_at || ! $trunk->evidence || ! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $trunk->name ?? '') || ! preg_match('/\A\+?[0-9]{3,15}\z/', $trunk->caller_id ?? '') || $trunk->max_channels <= $trunk->reserved_channels || $trunk->safe_channels < 1 || $trunk->max_cps <= 0 || ! in_array($trunk->number_format, ['national', 'international', 'e164'], true)) {
            $this->fail('Verified trunk, caller ID and capacity evidence are required.');
        }
    }

    public function snapshot(Campaign $campaign): array
    {
        if (! in_array($campaign->type, ['one_way', 'interactive'], true)) {
            $this->fail('Unsupported campaign type.');
        }
        $c = $campaign->configuration ?? [];
        Validator::make($c, [
            'audio_id' => 'required|integer|exists:audio_files,id', 'trunk_id' => 'required|integer|exists:trunk_profiles,id',
            'concurrency' => 'required|integer|min:1|max:100', 'cps' => 'required|numeric|gt:0|max:100',
            'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i|after:window_start',
            'contact_ids' => 'required|array|min:1|max:10000', 'contact_ids.*' => 'required|integer|distinct|exists:contacts,id',
        ])->validate();
        $audio = AudioFile::findOrFail($c['audio_id']);
        $trunk = TrunkProfile::findOrFail($c['trunk_id']);
        $this->audio($audio);
        $this->trunk($trunk);
        $configured = config('broadcasting_discovery.trunk');
        if (is_string($configured) && $configured !== '' && $trunk->name !== $configured) {
            $this->fail('Campaign must use the configured trunk.');
        }
        $callerId = empty($c['caller_id']) ? $trunk->caller_id : $c['caller_id'];
        if (! in_array($callerId, array_merge([$trunk->caller_id], $trunk->allowed_caller_ids ?? []), true)) {
            $this->fail('Caller ID must be approved for this trunk.');
        }
        $policy = app(CampaignPolicy::class)->schedule($c);
        $ivr = null;
        if ($campaign->type === 'interactive') {
            $flow = IvrFlow::find($c['ivr_flow_id'] ?? null);
            if (! $flow) {
                $this->fail('Select an approved deployed IVR flow.');
            }
            $ivr = app(IvrDesigner::class)->snapshot($flow);
        }
        $targets = Contact::whereIn('id', $c['contact_ids'])->orderBy('id')->get(['id', 'phone_e164'])->toArray();
        foreach ($targets as $target) {
            if (! preg_match('/\A\+233[235][0-9]{8}\z/', $target['phone_e164'] ?? '')) {
                $this->fail('Audience contains an invalid or legacy contact.');
            }
        }

        return [
            'audio_id' => $audio->id, 'audio_uuid' => $audio->uuid, 'checksum' => $audio->checksum, 'trunk_id' => $trunk->id,
            'trunk_name' => $trunk->name, 'caller_id' => $callerId, 'number_format' => $trunk->number_format,
            'concurrency' => (int) $c['concurrency'], 'cps' => (float) $c['cps'], 'timezone' => $policy['timezone'], 'scheduled_at' => $policy['scheduled_at'], 'priority' => $policy['priority'], 'ivr' => $ivr,
            'window_start' => $c['window_start'], 'window_end' => $c['window_end'], 'purpose' => $campaign->purpose,
            'targets' => $targets, 'retry_policy' => $policy['retry_policy'],
        ];
    }

    public function inWindow(array $snapshot): bool
    {
        $time = CarbonImmutable::now($snapshot['timezone'])->format('H:i');

        return $time >= $snapshot['window_start'] && $time < $snapshot['window_end'];
    }

    public function exclusion(int $contactId, string $phone, string $purpose): ?string
    {
        $contact = Contact::find($contactId);
        if (! $contact || $contact->phone_e164 !== $phone) {
            return 'contact_changed';
        }
        if (DB::table('suppression_entries')->where('phone_e164', $phone)->where('effective_at', '<=', now())->exists()) {
            return 'suppressed';
        }


        return null;
    }

    public function liveAssets(array $s): void
    {
        $audio = AudioFile::findOrFail($s['audio_id']);
        $trunk = TrunkProfile::findOrFail($s['trunk_id']);
        $this->audio($audio);
        $this->trunk($trunk);
        $configured = config('broadcasting_discovery.trunk');
        if (is_string($configured) && $configured !== '' && $trunk->name !== $configured) {
            $this->fail('Campaign must use the configured trunk.');
        }
        if ($audio->uuid !== $s['audio_uuid'] || $audio->checksum !== $s['checksum'] || $trunk->name !== $s['trunk_name'] || ! in_array($s['caller_id'], array_merge([$trunk->caller_id], $trunk->allowed_caller_ids ?? []), true) || $trunk->number_format !== $s['number_format']) {
            $this->fail('Approved assets changed; create a new campaign approval.');
        }
        if (! empty($s['ivr'])) {
            $flow = IvrFlow::findOrFail($s['ivr']['id']);
            $live = app(IvrDesigner::class)->snapshot($flow);
            if ($live !== $s['ivr']) {
                $this->fail('Approved IVR assets or routes changed.');
            }
        }

    }
}
