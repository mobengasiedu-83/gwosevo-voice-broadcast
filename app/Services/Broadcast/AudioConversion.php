<?php

namespace App\Services\Broadcast;

use App\Models\AudioFile;
use App\Models\User;
use App\Services\Notifications\Notifier;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class AudioConversion
{
    private const FORMATS = 'wav,mp3,mov,aac,ogg,flac,aiff,asf,matroska,webm';

    public function convert(int $id): void
    {
        // Jobs carry only an internal ID; HTTP account scopes must not hide it from workers.
        $audio = AudioFile::withoutGlobalScope('client')->find($id);
        if (! $audio || $audio->status !== 'processing') {
            return;
        }
        $reviewable = null;
        $disk = Storage::disk('local');
        $work = 'broadcast/audio-work/'.$audio->uuid;
        $target = 'broadcast/audio/'.$audio->uuid.'.wav';
        try {
            if (! $audio->source_key || ! $disk->exists($audio->source_key)) {
                throw new DomainException('The uploaded source file is missing. Please upload it again.');
            }
            $input = $disk->path($audio->source_key);
            $probe = new Process([
                config('voice_broadcast.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
                '-format_whitelist', self::FORMATS, '-show_entries',
                'format=duration:stream=codec_type,duration:stream_disposition=attached_pic', '-of', 'json', $input,
            ]);
            $probe->setTimeout(5);
            $probe->run();
            if (in_array($probe->getExitCode(), [126, 127], true)) {
                throw new DomainException('Audio processing is unavailable or timed out. Please contact support or upload an 8 kHz mono 16-bit PCM WAV.');
            }
            if (! $probe->isSuccessful()) {
                throw new DomainException('The recording could not be read. Upload a valid, unprotected audio file.');
            }
            if (strlen($probe->getOutput()) > 1000000) {
                throw new DomainException('The recording contains too many streams. Upload a simpler audio file.');
            }
            $info = json_decode($probe->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $streams = $info['streams'] ?? [];
            if (! collect($streams)->contains(fn ($s) => ($s['codec_type'] ?? '') === 'audio') ||
                collect($streams)->contains(fn ($s) => ($s['codec_type'] ?? '') === 'video' && ! ($s['disposition']['attached_pic'] ?? false))) {
                throw new DomainException('Upload an audio recording, rather than a video or a file without sound.');
            }
            $limit = (int) config('voice_broadcast.max_audio_seconds');
            $durations = array_merge([$info['format']['duration'] ?? 0], array_column($streams, 'duration'));
            foreach ($durations as $duration) {
                if (is_numeric($duration) && (float) $duration > $limit) {
                    throw new DomainException('Audio exceeds the duration limit. Upload a shorter recording.');
                }
            }
            $disk->makeDirectory($work);
            $output = $disk->path($work.'/output.wav');
            $process = new Process([
                config('voice_broadcast.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-xerror', '-y',
                '-protocol_whitelist', 'file,pipe', '-format_whitelist', self::FORMATS, '-i', $input,
                '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-threads', '1',
                '-ac', '1', '-ar', '8000', '-c:a', 'pcm_s16le', '-t', (string) ($limit + 1),
                '-fs', (string) ((int) config('voice_broadcast.max_audio_bytes') + 65536), '-f', 'wav', $output,
            ]);
            $process->setTimeout(40);
            $process->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                throw new DomainException('Audio conversion failed. Upload another recording or a PCM WAV file.');
            }
            $pcm = app(PcmWav::class)->read($output);
            if (! $disk->put($target, $pcm['bytes'])) {
                throw new DomainException('The converted recording could not be stored. Please upload it again.');
            }
            // Only verified PCM is visible to preview, approval and campaign selection.
            $updated = DB::table('audio_files')->where('id', $id)->where('status', 'processing')->update([
                'storage_key' => $target, 'checksum' => hash('sha256', $pcm['bytes']),
                'duration' => $pcm['duration'], 'status' => 'pending', 'source_key' => null,
                'processing_error' => null, 'updated_at' => now(),
            ]);
            if (! $updated) {
                $disk->delete($target);
            } else {
                $reviewable = $id;
            }
            $disk->delete($audio->source_key);
        } catch (Throwable $e) {
            // Never expose decoder output, source paths or embedded metadata to users/logs.
            $message = $e instanceof ValidationException
                ? 'The converted audio exceeds the limits or is invalid. Upload a shorter recording.'
                : ($e instanceof DomainException
                    ? $e->getMessage() : 'Audio processing is unavailable or timed out. Please contact support or upload an 8 kHz mono 16-bit PCM WAV.');
            $this->fail($id, $message);
        } finally {
            $disk->deleteDirectory($work);
        }
        // Alert reviewers only once the verified PCM WAV is in place; a failed
        // alert must never turn a successful conversion into a failure.
        if ($reviewable !== null && ($review = AudioFile::withoutGlobalScope('client')->find($reviewable))) {
            app(Notifier::class)->audioSubmitted($review, User::find($review->uploaded_by));
        }
    }

    public function fail(int $id, string $message): void
    {
        $audio = DB::table('audio_files')->where('id', $id)->where('status', 'processing')->first();
        if (! $audio) {
            return;
        }
        DB::table('audio_files')->where('id', $id)->where('status', 'processing')->update([
            'status' => 'conversion_failed', 'processing_error' => $message, 'source_key' => null, 'updated_at' => now(),
        ]);
        $disk = Storage::disk('local');
        if ($audio->source_key) {
            $disk->delete($audio->source_key);
        }
        $disk->delete('broadcast/audio/'.$audio->uuid.'.wav');
        $disk->deleteDirectory('broadcast/audio-work/'.$audio->uuid);
    }
}
