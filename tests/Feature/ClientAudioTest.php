<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\ClientNotice;
use App\Models\User;
use App\Services\Broadcast\AudioLibrary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientAudioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private ClientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->account = ClientAccount::create(['name' => 'Alpha', 'created_by' => $this->admin->id]);
        $other = ClientAccount::create(['name' => 'Beta', 'created_by' => $this->admin->id]);
        $this->alice = $this->client($this->account);
        $this->bob = $this->client($other);
    }

    private function client(ClientAccount $account): User
    {
        $user = User::factory()->create();
        $user->forceFill(['client_account_id' => $account->id])->save();
        $user->assignRole('Client');

        return $user;
    }

    private function wav(int $rate = 8000): string
    {
        $pcm = str_repeat(pack('v', 100), 800);

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function upload(User $user, string $name = 'Client message'): AudioFile
    {
        return app(AudioLibrary::class)->upload($user, UploadedFile::fake()->createWithContent('message.wav', $this->wav()), $name);
    }

    public function test_client_upload_is_pending_private_and_cannot_assign_status_account_or_approval(): void
    {
        $this->actingAs($this->alice)->get('/client/audio')->assertOk()->assertSee('Upload new audio');
        $this->post('/client/audio', ['name' => 'Hello message', 'audio' => UploadedFile::fake()->createWithContent('hello.wav', $this->wav()), 'status' => 'approved', 'approved_by' => $this->alice->id, 'client_account_id' => $this->bob->client_account_id])->assertRedirect(route('client.audio'));
        $audio = AudioFile::firstOrFail();
        $this->assertSame('pending', $audio->status);
        $this->assertSame($this->account->id, $audio->client_account_id);
        $this->assertNull($audio->approved_by);
        Storage::disk('local')->assertExists($audio->storage_key);
        $this->get('/client/audio')->assertOk()->assertSee('Hello message')->assertSee('Pending')->assertDontSee($audio->storage_key)->assertDontSee($audio->checksum);
        $this->get(route('client.audio.preview', $audio))->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('client.audio.download', $audio))->assertOk()->assertDownload($audio->uuid.'.wav');
        $this->assertFalse($this->alice->can('audio.approve'));
        $this->assertFalse($this->alice->can('audio.manage'));
    }

    public function test_account_library_filters_previews_and_downloads_do_not_leak_other_recordings(): void
    {
        $own = $this->upload($this->alice, 'Alpha message');
        $other = $this->upload($this->bob, 'Beta secret message');
        $this->actingAs($this->alice)->get('/client/audio')->assertOk()->assertSee('Alpha message')->assertDontSee('Beta secret message');
        $this->get('/client/audio?status=approved')->assertOk()->assertDontSee('Alpha message');
        $this->get('/client/audio?q=missing')->assertOk()->assertDontSee('Alpha message');
        $this->get(route('client.audio.preview', $other))->assertNotFound();
        $this->get(route('client.audio.download', $other))->assertNotFound();
        $this->post(route('client.audio.resubmit', $other))->assertNotFound();
        $this->get('/audio')->assertForbidden();
        $this->post('/broadcast/audio/'.$own->id.'/approve')->assertForbidden();
    }

    public function test_existing_internal_reviewer_approves_client_audio_and_readies_only_matching_account_campaign(): void
    {
        $audio = $this->upload($this->alice);
        $campaign = Campaign::create(['name' => 'Alpha draft', 'owner_id' => $this->alice->id, 'status' => 'draft', 'configuration' => ['audio_id' => $audio->id]]);
        $wrong = Campaign::create(['name' => 'Mismatched asset', 'owner_id' => $this->bob->id, 'status' => 'draft', 'configuration' => ['audio_id' => $audio->id]]);
        $this->actingAs($this->admin)->get('/audio')->assertOk()->assertSee('Client message');
        app(AudioLibrary::class)->approve($this->admin, $audio);
        $this->assertSame('approved', $audio->fresh()->status);
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertSame('draft', $wrong->fresh()->status);
        $this->assertSame(1, ClientNotice::where('kind', 'audio.approved')->count());
        $this->actingAs($this->alice)->get('/client/audio?status=approved')->assertOk()->assertSee('Client message')->assertSee('Approved');
    }

    public function test_rejection_resubmit_and_corrected_upload_preserve_existing_artifacts(): void
    {
        $audio = $this->upload($this->alice);
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Please shorten the introduction.');
        $this->actingAs($this->alice)->get('/client/audio')->assertOk()->assertSee('Please shorten the introduction.')->assertSee('Request another review');
        $checksum = $audio->checksum;
        $this->post(route('client.audio.resubmit', $audio))->assertRedirect();
        $this->assertSame('pending', $audio->fresh()->status);
        $this->assertNull($audio->fresh()->rejected_at);
        $this->assertSame($checksum, $audio->fresh()->checksum);
        $this->post(route('client.audio.resubmit', $audio))->assertSessionHasErrors('broadcast');
        $this->post('/client/audio', ['name' => 'Corrected recording', 'audio' => UploadedFile::fake()->createWithContent('fixed.wav', $this->wav())])->assertRedirect();
        $this->assertSame(2, AudioFile::count());
        $this->assertSame($checksum, $audio->fresh()->checksum);
    }

    public function test_invalid_wav_and_wrong_sample_rate_are_rejected_without_creating_assets(): void
    {
        $this->actingAs($this->alice)->post('/client/audio', ['name' => 'Invalid', 'audio' => UploadedFile::fake()->createWithContent('bad.wav', 'not audio')])->assertSessionHasErrors('broadcast');
        $this->post('/client/audio', ['name' => 'Wrong rate', 'audio' => UploadedFile::fake()->createWithContent('bad.wav', $this->wav(16000))])->assertSessionHasErrors('broadcast');
        $this->assertSame(0, AudioFile::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_integrity_failures_and_missing_files_block_preview_download_and_resubmit(): void
    {
        $audio = $this->upload($this->alice);
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Review again');
        Storage::disk('local')->put($audio->storage_key, 'tampered');
        $this->actingAs($this->alice)->get(route('client.audio.preview', $audio))->assertStatus(409);
        $this->get(route('client.audio.download', $audio))->assertStatus(409);
        $this->post(route('client.audio.resubmit', $audio))->assertSessionHasErrors('broadcast');
        $this->assertSame('rejected', $audio->fresh()->status);
        Storage::disk('local')->delete($audio->storage_key);
        $this->get(route('client.audio.preview', $audio))->assertNotFound();
    }

    public function test_guests_staff_suspended_accounts_and_cross_account_service_resubmission_are_denied(): void
    {
        $audio = $this->upload($this->alice);
        $this->get('/client/audio')->assertRedirect('/login');
        $this->actingAs($this->admin)->get('/client/audio')->assertForbidden();
        $this->account->update(['status' => 'suspended']);
        $this->actingAs($this->alice)->get('/client/audio')->assertForbidden();
        $this->assertGuest();
        $this->account->update(['status' => 'active']);
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Review again');
        $this->expectException(HttpException::class);
        app(AudioLibrary::class)->resubmit($this->bob, $audio);
    }

    public function test_same_account_colleague_can_preview_and_request_review(): void
    {
        $audio = $this->upload($this->alice);
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Revise');
        $peer = $this->client($this->account);
        $this->actingAs($peer)->get(route('client.audio.preview', $audio))->assertOk();
        $this->post(route('client.audio.resubmit', $audio))->assertRedirect();
        $this->assertSame('pending', $audio->fresh()->status);
    }

    public function test_authenticated_preview_supports_byte_ranges_for_audio_seeking(): void
    {
        $audio = $this->upload($this->alice);
        $size = Storage::disk('local')->size($audio->storage_key);
        $this->actingAs($this->alice)->withHeader('Range', 'bytes=0-43')->get(route('client.audio.preview', $audio))->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-43/'.$size);
    }
}
