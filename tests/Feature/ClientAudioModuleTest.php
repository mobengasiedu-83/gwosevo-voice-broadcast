<?php

namespace Tests\Feature;

use App\Jobs\ConvertAudio;
use App\Jobs\DeliverNotification;
use App\Mail\AccountAlert;
use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Broadcast\AudioConversion;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Accounts;
use App\Services\Identity\Delivery as SmsDelivery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
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
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->owner->id, 'event_key' => 'audio.approved']);
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

    public function test_background_conversion_preserves_account_ownership_and_review_boundaries(): void
    {
        Bus::fake([ConvertAudio::class]);
        $pcm = str_repeat("\0", 1600);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        $this->actingAs($this->owner);
        $audio = app(AudioLibrary::class)->upload($this->owner, UploadedFile::fake()->createWithContent('source.wav', $wav), 'Client conversion');
        $this->assertSame($this->client->id, $audio->client_id);
        $this->assertSame('processing', $audio->status);
        $this->get('/audio?status=processing')->assertOk()->assertSee('Client conversion')->assertDontSee('Approve audio');
        $this->actingAs($this->foreign)->get('/audio')->assertDontSee('Client conversion');
        // Worker completion must not inherit an unrelated authenticated account scope.
        (new ConvertAudio($audio->id))->handle(app(AudioConversion::class));
        $audio->refresh();
        $this->assertSame('pending', $audio->status, $audio->processing_error ?? '');
        $this->assertSame($this->client->id, $audio->client_id);
        $this->get('/audio/'.$audio->id.'/preview')->assertNotFound();
        $this->actingAs($this->member)->get('/audio/'.$audio->id.'/preview')->assertOk();
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertForbidden();
        $this->assertNull($audio->approved_by);
    }

    public function test_client_upload_emails_superadmin_administrator_and_approvers_for_review(): void
    {
        Mail::fake();
        Queue::fake();
        $administrator = User::factory()->create();
        $administrator->assignRole('Administrator');
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $analyst = User::factory()->create();
        $analyst->assignRole('Analyst');

        $audio = $this->upload();

        foreach ([$this->admin, $administrator, $approver] as $reviewer) {
            $alert = UserNotification::where('user_id', $reviewer->id)->sole();
            $this->assertSame('review', $alert->category);
            $this->assertSame('audio.submitted', $alert->event_key);
            $this->assertSame('warning', $alert->severity);
            $this->assertSame('Recording awaiting approval', $alert->title);
            $this->assertTrue($alert->in_app);
            $this->assertStringContainsString('Customer message', (string) $alert->body);
            $this->assertStringContainsString('Audio customer', (string) $alert->body);
            $this->assertSame($audio->id, $alert->data['audio_id']);
            $this->assertSame(route('audio.index', ['status' => 'pending']), $alert->url);
            $delivery = $alert->deliveries()->sole();
            $this->assertSame('email', $delivery->channel);
            $this->assertSame('queued', $delivery->status);
        }

        // Client logins and staff roles that cannot approve audio stay silent.
        $this->assertSame(0, UserNotification::whereIn('user_id', [$this->owner->id, $this->member->id, $analyst->id])->count());
        $this->assertSame(3, UserNotification::where('event_key', 'audio.submitted')->count());

        // The queued copy is a real email to each reviewer, not only an in-app badge.
        $email = UserNotification::where('user_id', $this->admin->id)->sole()->deliveries()->sole();
        (new DeliverNotification($email->id))->handle(app(SmsDelivery::class));
        $this->assertSame('sent', $email->fresh()->status);
        Mail::assertSent(AccountAlert::class, fn (AccountAlert $mail) => $mail->hasTo($this->admin->email)
            && $mail->alertTitle === 'Recording awaiting approval');

        // The alert is visible in the staff inbox, so approvals do not wait unseen.
        $this->actingAs($approver)->get(route('notifications.index'))->assertOk()->assertSee('Recording awaiting approval');
        $this->actingAs($approver)->get(route('audio.index', ['status' => 'pending']))->assertOk()->assertSee('Customer message');
        $this->actingAs($this->member)->get('/account/notifications')->assertDontSee('Recording awaiting approval');
    }

    public function test_converted_client_upload_notifies_reviewers_only_once_it_is_reviewable(): void
    {
        Bus::fake([ConvertAudio::class]);
        Mail::fake();
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $disk = Storage::disk('local');
        $disk->makeDirectory('fixtures');
        $path = $disk->path('fixtures/customer.mp3');
        (new Process([config('voice_broadcast.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-f', 'lavfi',
            '-i', 'sine=frequency=440:duration=0.3', '-ac', '1', '-ar', '8000', '-c:a', 'libmp3lame', $path]))->mustRun();

        $audio = app(AudioLibrary::class)->upload($this->owner, new UploadedFile($path, 'customer.mp3', null, null, true), 'Converted customer message');
        $this->assertSame('processing', $audio->status);
        $this->assertSame(0, UserNotification::where('event_key', 'audio.submitted')->count());

        (new ConvertAudio($audio->id))->handle(app(AudioConversion::class));

        $this->assertSame('pending', $audio->fresh()->status, $audio->fresh()->processing_error ?? '');
        $this->assertSame(1, UserNotification::where('user_id', $this->admin->id)->where('event_key', 'audio.submitted')->count());
        $this->assertSame(1, UserNotification::where('user_id', $approver->id)->where('event_key', 'audio.submitted')->count());
        $this->assertSame(0, UserNotification::where('user_id', $this->owner->id)->where('event_key', 'audio.submitted')->count());
    }

    public function test_resubmission_after_rejection_alerts_reviewers_again(): void
    {
        Mail::fake();
        $audio = $this->upload();
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Please re-record the intro');
        $alerts = fn () => UserNotification::where('user_id', $this->admin->id)->where('event_key', 'audio.submitted')->count();
        $this->assertSame(1, $alerts());

        app(AudioLibrary::class)->resubmit($this->owner, $audio->fresh());

        $this->assertSame(2, $alerts());
        $latest = UserNotification::where('user_id', $this->admin->id)->where('event_key', 'audio.submitted')->latest('id')->first();
        $this->assertStringContainsString('Customer message', (string) $latest->body);
        $this->assertSame(1, UserNotification::where('user_id', $this->owner->id)->where('event_key', 'audio.rejected')->count());
    }

    public function test_a_reviewers_own_upload_does_not_alert_them(): void
    {
        Mail::fake();
        $approver = User::factory()->create();
        $approver->assignRole('Approver');

        app(AudioLibrary::class)->upload($this->admin, $this->file(), 'Staff recording');

        $this->assertSame(0, UserNotification::where('user_id', $this->admin->id)->where('event_key', 'audio.submitted')->count());
        $this->assertSame(1, UserNotification::where('user_id', $approver->id)->where('event_key', 'audio.submitted')->count());
    }
}
