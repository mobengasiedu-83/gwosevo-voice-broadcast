<?php

namespace App\Services\Broadcast;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AudioLibrary
{
    public function upload(User $actor, UploadedFile $file, string $name): AudioFile
    {
        Gate::forUser($actor)->authorize('audio.manage');
        Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate();
        $ready = app(Readiness::class);
        if ($file->getSize() > config('voice_broadcast.max_audio_bytes') || ! in_array($file->getMimeType(), ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], true)) {
            $ready->fail('Upload a PCM WAV file within the size limit.');
        }
        $bytes = file_get_contents($file->getRealPath());
        if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE' || unpack('V', substr($bytes, 4, 4))[1] + 8 !== strlen($bytes)) {
            $ready->fail('Invalid WAV container.');
        }
        $fmt = null;
        $data = null;
        $offset = 12;
        while ($offset + 8 <= strlen($bytes)) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8;
            if ($size > strlen($bytes) - $offset) {
                $ready->fail('Truncated WAV data.');
            }
            if ($id === 'fmt ') {
                if ($fmt !== null) {
                    $ready->fail('Duplicate WAV format.');
                } $fmt = substr($bytes, $offset, $size);
            }
            if ($id === 'data') {
                if ($data !== null) {
                    $ready->fail('Duplicate WAV audio data.');
                } $data = substr($bytes, $offset, $size);
            }
            $offset += $size + ($size % 2);
        }
        if ($offset !== strlen($bytes) || $fmt === null || strlen($fmt) < 16 || $data === null || strlen($data) === 0 || strlen($data) % 2) {
            $ready->fail('WAV requires complete format and audio chunks.');
        }
        $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($fmt, 0, 16));
        if ($f !== ['format' => 1, 'channels' => 1, 'rate' => 8000, 'byterate' => 16000, 'align' => 2, 'bits' => 16]) {
            $ready->fail('Audio must be 8 kHz mono 16-bit PCM WAV. Convert it before upload.');
        }
        $duration = strlen($data) / 16000;
        if ($duration > config('voice_broadcast.max_audio_seconds')) {
            $ready->fail('Audio exceeds the duration limit.');
        }
        // Canonicalize: discard metadata/unknown chunks; only verified PCM bytes enter the PBX artifact.
        $canonical = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('V', 16).substr($fmt, 0, 16).'data'.pack('V', strlen($data)).$data;
        $uuid = (string) Str::uuid();
        $key = 'broadcast/audio/'.$uuid.'.wav';
        if (! Storage::disk('local')->put($key, $canonical)) {
            $ready->fail('Could not store private audio.');
        }
        try {
            return DB::transaction(function () use ($actor, $name, $duration, $uuid, $key, $canonical) {
                $audio = AudioFile::create(['uuid' => $uuid, 'name' => $name, 'storage_key' => $key, 'checksum' => hash('sha256', $canonical), 'duration' => $duration, 'status' => 'pending', 'uploaded_by' => $actor->id]);
                app(Foundation::class)->audit($actor, 'audio.uploaded', $audio, ['checksum' => $audio->checksum]);

                return $audio;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($key);
            throw $e;
        }
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
