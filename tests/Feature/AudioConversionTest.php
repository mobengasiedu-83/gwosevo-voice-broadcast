<?php

namespace Tests\Feature;

use App\Jobs\ConvertAudio;
use App\Models\AudioFile;
use App\Models\User;
use App\Services\Broadcast\AudioConversion;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\PcmWav;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Bus::fake([ConvertAudio::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
    }

    private function source(string $extension, string $codec): UploadedFile
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('fixtures');
        $path = $disk->path('fixtures/source.'.$extension);
        $process = new Process([config('voice_broadcast.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.3', '-ac', '2', '-ar', $codec === 'libopus' ? '48000' : '44100', '-c:a', $codec, $path]);
        $process->mustRun();

        return new UploadedFile($path, 'recording.'.$extension, null, null, true);
    }

    public static function formats(): array
    {
        return [
            'stereo 24-bit WAV' => ['wav', 'pcm_s24le'],
            'stereo MP3' => ['mp3', 'libmp3lame'],
            'M4A AAC' => ['m4a', 'aac'],
            'AAC' => ['aac', 'aac'],
            'OGG Vorbis' => ['ogg', 'libvorbis'],
            'Opus' => ['opus', 'libopus'],
            'FLAC' => ['flac', 'flac'],
            'AIFF' => ['aiff', 'pcm_s16be'],
            'WMA' => ['wma', 'wmav2'],
            'audio WebM' => ['webm', 'libopus'],
        ];
    }

    #[DataProvider('formats')]
    public function test_real_formats_convert_to_verified_private_pcm_before_review(string $extension, string $codec): void
    {
        $audio = app(AudioLibrary::class)->upload($this->admin, $this->source($extension, $codec), 'Converted recording');
        $this->assertSame('processing', $audio->status);
        $this->assertNull($audio->storage_key);
        $this->assertNull($audio->checksum);
        $source = $audio->source_key;
        Bus::assertDispatched(ConvertAudio::class, fn ($job) => $job->audioId === $audio->id);
        $this->actingAs($this->admin)->get('/audio/'.$audio->id.'/preview')->assertNotFound();
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertSessionHasErrors('broadcast');

        (new ConvertAudio($audio->id))->handle(app(AudioConversion::class));
        $audio->refresh();
        $this->assertSame('pending', $audio->status, $audio->processing_error ?? '');
        $this->assertNull($audio->source_key);
        $this->assertNull($audio->approved_at);
        $this->assertNull($audio->processing_error);
        $pcm = app(PcmWav::class)->read(Storage::disk('local')->path($audio->storage_key));
        $this->assertEqualsWithDelta(0.3, $pcm['duration'], 0.1);
        $this->assertSame(hash('sha256', $pcm['bytes']), $audio->checksum);
        Storage::disk('local')->assertMissing($source);
        $this->assertFalse(Storage::disk('local')->exists('broadcast/audio-work/'.$audio->uuid));
        $this->get('/audio/'.$audio->id.'/preview')->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('approved', $audio->fresh()->status);
        (new ConvertAudio($audio->id))->handle(app(AudioConversion::class));
        $this->assertSame($audio->checksum, $audio->fresh()->checksum);
    }

    public function test_invalid_audio_fails_cleans_up_and_shows_an_actionable_error(): void
    {
        $file = UploadedFile::fake()->createWithContent('broken.mp3', str_repeat("\0", 100));
        $audio = app(AudioLibrary::class)->upload($this->admin, $file, 'Broken recording');
        $source = $audio->source_key;
        app(AudioConversion::class)->convert($audio->id);
        $audio->refresh();
        $this->assertSame('conversion_failed', $audio->status);
        $this->assertNull($audio->checksum);
        $this->assertNull($audio->storage_key);
        Storage::disk('local')->assertMissing($source);
        $this->actingAs($this->admin)->get('/audio?status=conversion_failed')->assertOk()->assertSee('Broken recording')->assertSee('Upload a replacement')->assertSee('could not be read')->assertDontSee('Approve audio');
        $this->get('/broadcast/audio/'.$audio->id)->assertNotFound();
        $this->post('/broadcast/audio/'.$audio->id.'/resubmit')->assertSessionHasErrors('broadcast');
    }

    public function test_missing_tools_and_worker_failure_are_recorded_without_exposing_paths(): void
    {
        $file = $this->source('mp3', 'libmp3lame');
        $audio = app(AudioLibrary::class)->upload($this->admin, $file, 'Missing tool');
        config()->set('voice_broadcast.ffprobe', '/private/secret/missing-ffprobe');
        app(AudioConversion::class)->convert($audio->id);
        $this->assertSame('conversion_failed', $audio->fresh()->status);
        $this->assertStringNotContainsString('/private/', $audio->fresh()->processing_error);
        $this->assertStringContainsString('unavailable or timed out', $audio->fresh()->processing_error);
        $second = app(AudioLibrary::class)->upload($this->admin, $file, 'Interrupted');
        $source = $second->source_key;
        (new ConvertAudio($second->id))->failed(new \RuntimeException('secret decoder error'));
        $this->assertSame('conversion_failed', $second->fresh()->status);
        Storage::disk('local')->assertMissing($source);
    }

    public function test_duration_and_upload_size_limits_and_unsupported_files(): void
    {
        $file = $this->source('mp3', 'libmp3lame');
        $audio = app(AudioLibrary::class)->upload($this->admin, $file, 'Too long');
        config()->set('voice_broadcast.max_audio_seconds', 0);
        app(AudioConversion::class)->convert($audio->id);
        $this->assertSame('conversion_failed', $audio->fresh()->status);
        $this->assertStringContainsString('duration limit', $audio->fresh()->processing_error);
        $this->actingAs($this->admin)->post('/broadcast/audio', ['name' => 'Not audio', 'audio' => UploadedFile::fake()->createWithContent('file.php', '<?php echo "bad";')])->assertSessionHasErrors('broadcast');
        config()->set('voice_broadcast.max_audio_bytes', 10);
        $this->post('/broadcast/audio', ['name' => 'Too large', 'audio' => $file])->assertSessionHasErrors();
    }

    public function test_processing_rows_show_progress_and_no_media_links(): void
    {
        $audio = app(AudioLibrary::class)->upload($this->admin, $this->source('mp3', 'libmp3lame'), 'Processing recording');
        $response = $this->actingAs($this->admin)->get('/audio?status=processing')->assertOk()->assertSee('Processing recording')->assertSee('Converting recording')->assertSee('data-audio-processing', false)->assertDontSee('/audio/'.$audio->id.'/preview', false)->assertDontSee('Approve audio');
        if ($path = getenv('AUDIO_BROWSER_FIXTURE')) {
            file_put_contents($path, $this->get('/audio')->assertOk()->getContent());
            app(AudioConversion::class)->convert($audio->id);
            file_put_contents($path.'.complete', $this->get('/audio')->assertOk()->getContent());

            return;
        }
        $this->assertSame(1, AudioFile::where('status', 'processing')->count());
    }
}
