<?php

namespace App\Services\Broadcast;

use App\Jobs\ConvertAudio;
use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Notifications\Notifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AudioLibrary
{
    public function upload(User $actor, UploadedFile $file, string $name): AudioFile
    {
        Gate::forUser($actor)->authorize('audio.manage');
        Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate();
        $ready = app(Readiness::class);
        if (! $file->isValid() || $file->getSize() > config('voice_broadcast.max_audio_bytes') || $file->getSize() < 1) {
            $ready->fail('Upload an audio file within the size limit.');
        }
        $extensions = ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'aif', 'aiff', 'wma', 'webm'];
        $mime = $file->getMimeType();
        if (! in_array(strtolower($file->getClientOriginalExtension()), $extensions, true) ||
            (! str_starts_with($mime ?? '', 'audio/') && ! in_array($mime, ['video/mp4', 'video/webm', 'video/x-ms-asf', 'video/x-ms-wmv', 'application/vnd.ms-asf', 'application/ogg', 'application/octet-stream'], true))) {
            $ready->fail('Upload WAV, MP3, M4A, AAC, OGG, Opus, FLAC, AIFF, WMA or WebM audio.');
        }
        $pcm = null;
        if (in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)) {
            try {
                $pcm = app(PcmWav::class)->read($file->getRealPath());
            } catch (ValidationException $e) {
                // Other WAV sample rates, channels and encodings are converted too.
            }
        }
        $uuid = (string) Str::uuid();
        $key = $pcm ? 'broadcast/audio/'.$uuid.'.wav' : 'broadcast/audio-sources/'.$uuid.'.source';
        if (! Storage::disk('local')->put($key, $pcm ? $pcm['bytes'] : file_get_contents($file->getRealPath()))) {
            $ready->fail('Could not store private audio.');
        }
        try {
            $audio = DB::transaction(function () use ($actor, $name, $uuid, $key, $pcm, $file) {
                $audio = AudioFile::create([
                    'uuid' => $uuid, 'name' => $name, 'uploaded_by' => $actor->id,
                    'original_name' => basename($file->getClientOriginalName()),
                    'storage_key' => $pcm ? $key : null, 'source_key' => $pcm ? null : $key,
                    'checksum' => $pcm ? hash('sha256', $pcm['bytes']) : null,
                    'duration' => $pcm['duration'] ?? null, 'status' => $pcm ? 'pending' : 'processing',
                ]);
                app(Foundation::class)->audit($actor, 'audio.uploaded', $audio, ['checksum' => $audio->checksum, 'status' => $audio->status]);

                return $audio;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($key);
            throw $e;
        }
        if (! $pcm) {
            try {
                ConvertAudio::dispatch($audio->id)->afterCommit();
            } catch (\Throwable $e) {
                app(AudioConversion::class)->fail($audio->id, 'Audio processing could not be queued. Please upload the recording again.');
            }
        }
        $audio = $audio->refresh();
        // WAV uploads are reviewable immediately; converted sources alert from
        // AudioConversion once the verified PCM file exists.
        if ($audio->status === 'pending') {
            app(Notifier::class)->audioSubmitted($audio, $actor);
        }

        return $audio;
    }

    public function approve(User $actor, AudioFile $audio): void
    {
        Gate::forUser($actor)->authorize('audio.approve');
        DB::transaction(function () use ($actor, $audio) {
            app(Engine::class)->mutex();
            $audio = AudioFile::whereKey($audio->id)->lockForUpdate()->firstOrFail();
            abort_if($audio->uploaded_by === $actor->id && ! $actor->isSuperAdministrator(), 403, 'A different user must approve audio.');
            if ($audio->status !== 'pending') {
                app(Readiness::class)->fail('Only pending audio can be approved.');
            }
            if (! $audio->checksum || ! Storage::disk('local')->exists($audio->storage_key ?? '') || ! hash_equals($audio->checksum, hash_file('sha256', Storage::disk('local')->path($audio->storage_key)))) {
                app(Readiness::class)->fail('Audio checksum mismatch.');
            }
            $audio->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            foreach (Campaign::where('client_id', $audio->client_id)->where('status', 'draft')->whereNull('archived_at')->whereIn('configuration->audio_id', [$audio->id, (string) $audio->id])->lockForUpdate()->get() as $campaign) {
                $campaign->update(['status' => 'ready']);
                app(Foundation::class)->audit($actor, 'campaign.audio_ready', $campaign, ['audio_id' => $audio->id]);
            }
            app(Foundation::class)->audit($actor, 'audio.approved', $audio, ['checksum' => $audio->checksum, 'self_approval' => $audio->uploaded_by === $actor->id]);
        });
        app(Notifier::class)->audioReviewed($audio, 'approved', null, $actor);
    }

    public function reject(User $actor, AudioFile $audio, string $reason): void
    {
        Gate::forUser($actor)->authorize('audio.approve');
        $reason = trim($reason);
        Validator::make(compact('reason'), ['reason' => 'required|string|max:2000'])->validate();
        DB::transaction(function () use ($actor, $audio, $reason) {
            app(Engine::class)->mutex();
            $audio = AudioFile::whereKey($audio->id)->lockForUpdate()->firstOrFail();
            abort_if($audio->uploaded_by === $actor->id && ! $actor->isSuperAdministrator(), 403);
            if ($audio->status !== 'pending') {
                app(Readiness::class)->fail('Only pending audio can be rejected.');
            }
            $audio->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            app(Foundation::class)->audit($actor, 'audio.rejected', $audio, ['reason' => $reason]);
        });
        app(Notifier::class)->audioReviewed($audio, 'rejected', $reason, $actor);
    }

    public function resubmit(User $actor, AudioFile $audio): void
    {
        Gate::forUser($actor)->authorize('audio.manage');
        DB::transaction(function () use ($actor, $audio) {
            app(Engine::class)->mutex();
            $audio = AudioFile::whereKey($audio->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(Tenancy::class)->sameAccount($actor, $audio) || $audio->uploaded_by === $actor->id || $actor->isSuperAdministrator(), 403);
            if ($audio->status !== 'rejected') {
                app(Readiness::class)->fail('Only rejected audio can be submitted again.');
            }
            if (! $audio->checksum || ! Storage::disk('local')->exists($audio->storage_key ?? '') || ! hash_equals($audio->checksum, hash_file('sha256', Storage::disk('local')->path($audio->storage_key)))) {
                app(Readiness::class)->fail('The recording is missing or changed. Upload a new WAV for review.');
            }
            $audio->update(['status' => 'pending', 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null]);
            app(Foundation::class)->audit($actor, 'audio.resubmitted', $audio);
        });
        // A resubmission is a fresh review request, so reviewers are alerted again.
        app(Notifier::class)->audioSubmitted($audio->refresh(), $actor);
    }

    public function deployed(User $actor, AudioFile $audio, string $checksum, string $evidence): void
    {
        Gate::forUser($actor)->authorize('telephony.manage');
        DB::transaction(function () use ($actor, $audio, $checksum, $evidence) {
            app(Engine::class)->mutex();
            $audio = AudioFile::whereKey($audio->id)->lockForUpdate()->firstOrFail();
            if ($audio->status !== 'approved' || ! hash_equals($audio->checksum, $checksum)) {
                app(Readiness::class)->fail('Deployment checksum must match approved audio.');
            }
            $audio->update(['deployed_at' => now(), 'deployment_evidence' => $evidence]);
            app(Foundation::class)->audit($actor, 'audio.deployed', $audio, ['checksum' => $checksum, 'evidence' => $evidence]);
        });
    }
}
