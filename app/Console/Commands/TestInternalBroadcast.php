
<?php

namespace App\Console\Commands;

use App\Models\AudioFile;
use App\Services\Broadcast\AmiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestInternalBroadcast extends Command
{
    protected $signature = 'broadcast:test-internal
        {--extension=208 : Internal extension}
        {--audio=1 : Approved audio ID}';

    protected $description =
    'Place one authorized HTTP audio playback test to extension 208';

    public function handle(): int
    {
        if (
            (string) $this->option('extension') !== '208'
            || (string) $this->option('audio') !== '1'
        ) {
            $this->error('Only extension 208 and Audio ID 1 are permitted.');

            return self::FAILURE;
        }

        $audio = AudioFile::find(1);

        if (! $audio || $audio->status !== 'approved') {
            $this->error('Audio ID 1 is not approved.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($audio->storage_key)) {
            $this->error('Approved audio file is missing.');

            return self::FAILURE;
        }

        $path = $disk->path($audio->storage_key);

        if (
            ! is_string($audio->checksum)
            || ! hash_equals(
                $audio->checksum,
                hash_file('sha256', $path)
            )
        ) {
            $this->error('Audio checksum verification failed.');

            return self::FAILURE;
        }

        $baseUrl = rtrim(
            (string) config('voice_broadcast.media_base_url'),
            '/'
        );

        if ($baseUrl !== 'http://173.16.254.253:85') {
            $this->error('Unexpected media server URL.');

            return self::FAILURE;
        }

        $this->info('Internal broadcast test');
        $this->line('Destination: PJSIP/208');
        $this->line('Audio ID: 1');
        $this->line('Duration: ' . $audio->duration . ' seconds');

        if (! $this->confirm('Place one internal test call?', false)) {
            $this->warn('Test cancelled.');

            return self::SUCCESS;
        }

        $token = bin2hex(random_bytes(32));
        $key = 'broadcast-internal-test:' . $token;
        $actionId = 'vb-internal-' . Str::uuid();

        $url = $baseUrl . '/internal-test-media/' . $token . '.wav';

        $ami = new AmiClient();
        $originateSubmitted = false;

        try {
            Cache::put($key, [
                'audio_id' => 1,
                'extension' => '208',
            ], now()->addSeconds(90));

            $ami->connect();

            $ami->send([
                'Action' => 'Originate',
                'ActionID' => $actionId,
                'Channel' => 'PJSIP/208',
                'Context' => 'gwosevo-http-test',
                'Exten' => 's',
                'Priority' => 1,
                'Async' => 'true',
                'Timeout' => 30000,
                'CallerID' => 'GWO SEVO Test <208>',
                'Variable' => [
                    '__VB_TEST_URL=' . $url,
                ],
            ]);

            $originateSubmitted = true;

            $this->info('AMI Originate submitted.');
            $this->line('Waiting for Asterisk response...');

            $deadline = microtime(true) + 45;
            $accepted = false;
            $completed = false;
            $failed = false;

            while (microtime(true) < $deadline) {
                foreach ($ami->poll() as $frame) {
                    $matchingAction =
                        ($frame['ActionID'] ?? '') === $actionId;

                    if (
                        $matchingAction
                        && isset($frame['Response'])
                    ) {
                        if ($frame['Response'] !== 'Success') {
                            throw new RuntimeException(
                                'AMI rejected the originate request.'
                            );
                        }

                        $accepted = true;
                        $this->info('Originate accepted by AMI.');
                    }

                    if (($frame['Event'] ?? '') === 'OriginateResponse'
                        && $matchingAction
                        && ($frame['Response'] ?? '') === 'Failure'
                    ) {
                        $failed = true;
                        $this->error('Asterisk could not originate the call.');
                    }

                    if (($frame['Event'] ?? '') === 'UserEvent'
                        && ($frame['UserEvent'] ?? '') === 'VBInternalTestComplete'
                        && ($frame['TestActionID'] ?? '') === $actionId
                    ) {
                        $completed = true;
                    }

                    if (($frame['Event'] ?? '') === 'UserEvent'
                        && ($frame['UserEvent'] ?? '') === 'VBInternalTestFailed'
                        && ($frame['TestActionID'] ?? '') === $actionId
                    ) {
                        $failed = true;
                    }
                }

                if ($completed || $failed) {
                    break;
                }

                usleep(100000);
            }

            if ($completed) {
                $this->info('Asterisk reported successful playback.');

                return self::SUCCESS;
            }

            if ($failed) {
                return self::FAILURE;
            }

            $this->warn(
                $accepted
                    ? 'AMI accepted the call, but playback completion was not confirmed.'
                    : 'No conclusive AMI response received.'
            );

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Internal test error: ' . $e->getMessage());

            if ($originateSubmitted) {
                $this->warn(
                    'The call may still be active. Check VitalPBX before retrying.'
                );
            }

            return self::FAILURE;
        } finally {
            $ami->close();

            // Do not revoke media authorization while a call might
            // still be downloading or playing the recording.
            // The cache entry expires automatically after 90 seconds.
            if (! $originateSubmitted) {
                Cache::forget($key);
            }
        }
    }
}
