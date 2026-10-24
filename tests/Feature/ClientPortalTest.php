<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientAccount;
use App\Models\ClientNotice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Clients\Portal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private ClientAccount $a;

    private ClientAccount $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->a = ClientAccount::create(['name' => 'Alpha Organization', 'created_by' => $this->admin->id]);
        $this->b = ClientAccount::create(['name' => 'Beta Secret', 'created_by' => $this->admin->id]);
        $this->alice = $this->client($this->a);
        $this->bob = $this->client($this->b);
    }

    private function client(ClientAccount $account): User
    {
        $user = User::factory()->create();
        $user->forceFill(['client_account_id' => $account->id])->save();
        $user->assignRole('Client');

        return $user;
    }

    private function audio(User $owner, string $name = 'Client recording'): AudioFile
    {
        Storage::fake('local');
        $key = Str::uuid().'.wav';
        Storage::disk('local')->put($key, 'test pcm bytes');

        return AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => $name, 'uploaded_by' => $owner->id, 'storage_key' => $key, 'checksum' => hash('sha256', 'test pcm bytes'), 'status' => 'pending']);
    }

    public function test_dashboard_and_campaign_summaries_are_account_scoped_and_hide_internal_configuration(): void
    {
        $ca = Campaign::create(['name' => 'Alpha Campaign', 'owner_id' => $this->alice->id, 'status' => 'completed', 'configuration' => ['caller_id' => 'SECRET-CALLER', 'trunk_id' => 'SECRET-TRUNK']]);
        Campaign::create(['name' => 'Beta Campaign Secret', 'owner_id' => $this->bob->id, 'status' => 'running']);
        $contact = Contact::create(['phone_e164' => '+233241234567', 'created_by' => $this->alice->id]);
        $recipient = CampaignRecipient::create(['campaign_id' => $ca->id, 'contact_id' => $contact->id]);
        CallAttempt::create(['campaign_recipient_id' => $recipient->id, 'attempt_no' => 1, 'playback_completed_at' => now()]);
        CallAttempt::create(['campaign_recipient_id' => $recipient->id, 'attempt_no' => 2, 'playback_completed_at' => now()]);
        $this->actingAs($this->alice)->get('/client')->assertOk()->assertSee('Alpha Campaign')->assertSee('Recipients reached')->assertDontSee('Beta Campaign Secret')->assertDontSee('SECRET-CALLER')->assertDontSee('SECRET-TRUNK');
        $this->assertSame(1, app(Portal::class)->summary($this->alice)['delivered']);
        $this->assertSame(1, (int) app(Portal::class)->campaigns($this->alice)->first()->delivered_count);
        $this->assertSame(1, (int) app(Portal::class)->campaigns($this->alice)->first()->audience_count);
        $this->get('/client/campaigns')->assertOk()->assertSee('Alpha Campaign')->assertSee('execution ended', false)->assertDontSee('Beta Campaign Secret');
        $this->get('/client/campaigns?status=running')->assertOk()->assertDontSee('Alpha Campaign')->assertDontSee('Beta Campaign Secret');
        $this->get('/client/campaigns?status=invalid')->assertSessionHasErrors('status');
    }

    public function test_internal_reviews_create_account_notifications_and_read_status_is_per_user(): void
    {
        $audio = $this->audio($this->alice);
        app(AudioLibrary::class)->approve($this->admin, $audio);
        $notice = ClientNotice::firstOrFail();
        $this->assertSame($this->a->id, $notice->client_account_id);
        $this->assertSame('audio.approved', $notice->kind);
        app(Portal::class)->reviewNotice($audio->fresh());
        $this->assertSame(1, ClientNotice::count());
        $this->actingAs($this->alice)->get('/client/notifications')->assertOk()->assertSee('Client recording')->assertSee('Unread');
        $this->post(route('client.notifications.read', $notice->id))->assertRedirect();
        $this->post(route('client.notifications.read', $notice->id))->assertRedirect();
        $this->assertSame(1, DB::table('client_notice_reads')->count());
        $this->assertSame(0, app(Portal::class)->unread($this->alice));
        $peer = $this->client($this->a);
        $this->assertSame(1, app(Portal::class)->unread($peer));
        $this->actingAs($peer)->post('/client/notifications/read-all')->assertRedirect();
        $this->assertSame(0, app(Portal::class)->unread($peer));
    }

    public function test_other_account_notifications_cannot_be_read_and_client_cannot_approve_audio(): void
    {
        $audio = $this->audio($this->bob, 'Beta secret recording');
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Invalid script');
        $notice = ClientNotice::firstOrFail();
        $this->actingAs($this->alice)->get('/client/notifications')->assertOk()->assertDontSee('Beta secret recording');
        $this->post(route('client.notifications.read', $notice->id))->assertNotFound();
        $this->post('/client/notifications/read-all')->assertRedirect();
        $this->assertSame(0, DB::table('client_notice_reads')->count());
        $this->assertNull(ClientNotice::find($notice->id));
        $this->get('/telephony')->assertForbidden();
        $this->get('/audio')->assertForbidden();
        $this->assertFalse($this->alice->can('audio.approve'));
    }

    public function test_guests_staff_and_suspended_accounts_cannot_enter_client_portal(): void
    {
        foreach (['/client', '/client/campaigns', '/client/notifications', '/client/settings'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs($this->admin)->get('/client')->assertForbidden();
        $this->get('/client/notifications')->assertForbidden();
        $this->a->update(['status' => 'suspended']);
        $this->actingAs($this->alice)->get('/client')->assertForbidden();
        $this->assertGuest();
    }

    public function test_empty_dashboard_settings_and_responsive_theme_controls_render(): void
    {
        $this->actingAs($this->alice)->get('/client')->assertOk()->assertSee('No campaigns to show')->assertSee('You’re all caught up')->assertSee('data-theme-toggle', false)->assertSee('Client account');
        $this->get('/client/settings')->assertOk()->assertSee('Client navigation')->assertSee($this->alice->email)->assertDontSee($this->bob->email)->assertDontSee('Beta Secret');
    }

    public function test_backfill_is_idempotent_and_reviews_of_suspended_accounts_still_record_notifications(): void
    {
        $audio = $this->audio($this->alice);
        $this->a->update(['status' => 'suspended']);
        app(AudioLibrary::class)->reject($this->admin, $audio, 'Please change the message.');
        $this->artisan('client:sync-review-notices')->assertSuccessful();
        $this->artisan('client:sync-review-notices')->assertSuccessful();
        $this->assertSame(1, ClientNotice::count());
        $this->assertStringContainsString('Please change the message.', ClientNotice::first()->message);
    }

    public function test_campaign_pagination_preserves_filters_and_account_boundary(): void
    {
        for ($i = 0; $i < 17; $i++) {
            Campaign::create(['name' => 'Alpha Run '.$i, 'owner_id' => $this->alice->id, 'status' => 'ready']);
        }
        Campaign::create(['name' => 'Alpha Run Hidden', 'owner_id' => $this->bob->id, 'status' => 'ready']);
        $this->actingAs($this->alice)->get('/client/campaigns?status=ready&q=Alpha&page=2')->assertOk()->assertSee('Page 2 of 2')->assertSee('status=ready')->assertDontSee('Alpha Run Hidden');
    }
}
