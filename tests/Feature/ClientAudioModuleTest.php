<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Accounts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientAudioModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $member;

    private User $foreign;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Audio customer');
        $other = $accounts->create($this->admin, 'Foreign customer');
        foreach ([$this->client, $other] as $client) {
            $accounts->status($this->admin, $client, 'active', 'Ready');
        }
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->foreign = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $this->client, $this->member);
        $accounts->member($this->admin, $other, $this->foreign, 'owner');
    }

    private function file(): UploadedFile
    {
        $pcm = str_repeat("\0", 1600);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;

        return UploadedFile::fake()->createWithContent('message.wav', $wav);
    }

    private function upload(): AudioFile
    {
        return app(AudioLibrary::class)->upload($this->owner, $this->file(), 'Customer message');
    }

    public function test_client_upload_preview_review_and_shared_resubmission_workflow(): void
    {
        $audio = $this->upload();
        $this->assertSame($this->client->id, $audio->client_id);
        $this->assertSame('pending', $audio->status);
        $this->actingAs($this->member)->get('/audio?q=Customer&status=pending')->assertOk()->assertSee('Customer message')->assertDontSee('Approve audio')->assertDontSee('approve or reject it')->assertDontSee('@else')->assertSee('Internal reviewers')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/audio?q=missing')->assertDontSee('Customer message');
        $this->get('/audio/'.$audio->id.'/preview', ['Range' => 'bytes=0-43'])->assertStatus(206)->assertHeader('Content-Type', 'audio/wav');
        $this->get('/broadcast/audio/'.$audio->id)->assertOk()->assertDownload();
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertForbidden();
        $this->post('/broadcast/audio/'.$audio->id.'/reject', ['reason' => 'Client approval attempt'])->assertForbidden();
        $this->actingAs($this->admin)->post('/broadcast/audio/'.$audio->id.'/reject', ['reason' => 'Please revise the opening'])->assertRedirect();
        $this->actingAs($this->member)->get('/audio?status=rejected')->assertSee('Please revise the opening')->assertSee('Resubmit for review');
        $this->post('/broadcast/audio/'.$audio->id.'/resubmit')->assertRedirect();
        $this->assertSame('pending', $audio->fresh()->status);
        $this->assertNull($audio->fresh()->rejection_reason);
        $campaign = Campaign::create(['client_id' => $this->client->id, 'owner_id' => $this->owner->id, 'name' => 'Awaiting audio', 'purpose' => 'Updates', 'status' => 'draft', 'configuration' => ['audio_id' => $audio->id]]);
        $this->actingAs($this->admin);
        $foreignCampaign = Campaign::create(['client_id' => $this->foreign->clientMembership->client_id, 'owner_id' => $this->foreign->id, 'name' => 'Invalid foreign reference', 'purpose' => 'Test', 'status' => 'draft', 'configuration' => ['audio_id' => $audio->id]]);
        $this->actingAs($this->admin)->post('/broadcast/audio/'.$audio->id.'/approve')->assertRedirect();
        $this->assertSame('draft', $foreignCampaign->fresh()->status);
        $this->assertSame('approved', $audio->fresh()->status);
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['client_id' => $this->client->id, 'action' => 'audio.resubmitted', 'actor_id' => $this->member->id]);
        $this->actingAs($this->member)->get('/account/notifications')->assertSee('Recording approved');
    }

    public function test_foreign_accounts_cannot_preview_download_review_or_resubmit(): void
    {
        $audio = $this->upload();
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Private review reason');
        $this->actingAs($this->foreign)->get('/audio')->assertOk()->assertDontSee('Customer message')->assertDontSee('Private review reason');
        $this->get('/audio/'.$audio->id.'/preview')->assertNotFound();
        $this->get('/broadcast/audio/'.$audio->id)->assertNotFound();
        foreach (['approve', 'reject', 'resubmit', 'deploy'] as $action) {
            $this->post('/broadcast/audio/'.$audio->id.'/'.$action, ['reason' => 'Forged'])->assertNotFound();
        }
    }

    public function test_missing_or_changed_recording_cannot_be_resubmitted_or_approved(): void
    {
        $audio = $this->upload();
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Review again');
        Storage::disk('local')->put($audio->storage_key, 'changed');
        $this->actingAs($this->member)->post('/broadcast/audio/'.$audio->id.'/resubmit')->assertSessionHasErrors('broadcast');
        $this->assertSame('rejected', $audio->fresh()->status);
        Storage::disk('local')->delete($audio->storage_key);
        $this->post('/broadcast/audio/'.$audio->id.'/resubmit')->assertSessionHasErrors('broadcast');
        $audio->update(['status' => 'pending']);
        $this->actingAs($this->admin)->post('/broadcast/audio/'.$audio->id.'/approve')->assertSessionHasErrors('broadcast');
        Storage::disk('local')->put($audio->storage_key, 'legacy file without checksum');
        $audio->update(['checksum' => null]);
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertSessionHasErrors('broadcast');
    }

    public function test_unapproved_audio_fails_launch_readiness_and_rejection_requires_a_reason_in_service(): void
    {
        $audio = $this->upload();
        foreach (['pending', 'rejected'] as $status) {
            $audio->update(['status' => $status]);
            try {
                app(Readiness::class)->audio($audio);
                $this->fail('Unapproved recording passed launch readiness.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('must be approved', $e->errors()['broadcast'][0]);
            }
        }
        $audio->update(['status' => 'pending']);
        $this->expectException(ValidationException::class);
        app(AudioLibrary::class)->reject($this->admin, $audio, '   ');
    }

    public function test_http_upload_ignores_forged_ownership_and_approval_fields(): void
    {
        $this->actingAs($this->member)->post('/broadcast/audio', [
            'name' => 'New client WAV', 'audio' => $this->file(),
            'client_id' => $this->foreign->clientMembership->client_id,
            'status' => 'approved', 'approved_by' => $this->admin->id,
            'storage_key' => 'public/forged.wav',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $audio = AudioFile::where('name', 'New client WAV')->firstOrFail();
        $this->assertSame($this->client->id, $audio->client_id);
        $this->assertSame('pending', $audio->status);
        $this->assertNull($audio->approved_by);
        $this->assertStringStartsWith('broadcast/audio/', $audio->storage_key);
        $this->member->assignRole('Super Administrator');
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertForbidden();
        $this->assertSame('pending', $audio->fresh()->status);
    }
}
