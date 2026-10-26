<?php

namespace Tests\Feature;

use App\Mail\IdentityCode;
use App\Models\AudioFile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\Accounts;
use App\Services\Clients\Notices;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientPortalReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private User $colleague;

    private Client $client;

    private string $password = 'StrongPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('turnstile.enabled', false);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Shared customer');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create(['password' => $this->password]);
        $this->colleague = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');
        $accounts->member($this->admin, $this->client, $this->colleague);
    }

    public function test_profile_changes_are_account_scoped_and_cannot_change_signin_or_privileges(): void
    {
        $email = $this->member->email;
        $other = app(Accounts::class)->create($this->admin, 'Other customer');
        $this->actingAs($this->member)->get('/account/profile')->assertOk()->assertSee('Account profile')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/account/profile', ['name' => 'Updated customer', 'account_type' => 'business', 'business_name' => 'Retail business', 'contact_email' => 'business@example.com', 'client_id' => $other->id, 'status' => 'closed', 'role' => 'Super Administrator', 'email' => 'hijack@example.com'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated customer', $this->client->fresh()->name);
        $this->assertSame('Retail business', $this->client->fresh()->profile['business_name']);
        $this->assertSame('active', $this->client->fresh()->status);
        $this->assertSame($email, $this->member->fresh()->email);
        $this->assertFalse($this->member->fresh()->isSuperAdministrator());
        $this->assertSame('Other customer', $other->fresh()->name);
        $this->actingAs($this->colleague)->get('/account/profile')->assertSee('Updated customer')->assertSee('business@example.com');
        $this->actingAs($this->admin)->get('/clients/'.$this->client->id.'?tab=profile')->assertSee('Retail business');
        $this->assertDatabaseHas('audit_logs', ['client_id' => $this->client->id, 'action' => 'client.profile_updated', 'actor_id' => $this->member->id]);
        $this->post('/account/profile', ['name' => 'Not a client'])->assertForbidden();
    }

    public function test_password_and_session_controls_require_current_password_and_revoke_stale_sessions(): void
    {
        $this->actingAs($this->member)->get('/account/security')->assertOk()->assertSee('Sign out other sessions')->assertSee('Change password');
        $this->post('/account/security', ['action' => 'sessions', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertSame(0, $this->member->fresh()->auth_version);
        $this->post('/account/security', ['action' => 'password', 'current_password' => $this->password, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/account/security', ['action' => 'sessions', 'current_password' => $this->password])->assertRedirect()->assertSessionHas('auth_version', 1);
        $this->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.sessions_revoked', 'actor_id' => $this->member->id]);
        $new = 'AnotherStrongPassword123!';
        $this->post('/account/security', ['action' => 'password', 'current_password' => $this->password, 'password' => $new, 'password_confirmation' => $new])->assertRedirect()->assertSessionHas('auth_version', 2);
        $this->assertTrue(Hash::check($new, $this->member->fresh()->password));
        $this->get('/dashboard')->assertOk();
        $this->withSession(['auth_version' => 1])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_previously_verified_recovery_cannot_override_a_later_security_change(): void
    {
        Mail::fake();
        $this->post('/forgot-password', ['channel' => 'email', 'identifier' => $this->member->email])->assertRedirect();
        $code = Mail::sent(IdentityCode::class)->last()->code;
        $this->post('/verify-contact', ['code' => $code])->assertRedirect('/set-password');
        $identity = session('identity');
        $this->actingAs($this->member)->post('/account/security', ['action' => 'sessions', 'current_password' => $this->password])->assertRedirect();
        $this->post('/logout')->assertRedirect();
        $new = 'AnotherStrongPassword123!';
        $this->withSession(['identity' => $identity])->post('/set-password', ['password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check($this->password, $this->member->fresh()->password));
    }

    private function recording(Client $client, string $name): AudioFile
    {
        return AudioFile::create(['client_id' => $client->id, 'uploaded_by' => $this->admin->id, 'uuid' => (string) Str::uuid(), 'name' => $name, 'storage_key' => 'notices/test.wav', 'status' => 'rejected']);
    }

    public function test_notices_are_scoped_sanitized_and_read_independently_by_each_member(): void
    {
        $other = app(Accounts::class)->create($this->admin, 'Secret customer');
        $own = $this->recording($this->client, 'Own recording');
        $foreign = $this->recording($other, 'Secret recording');
        app(Foundation::class)->audit($this->admin, 'audio.rejected', $own, ['reason' => 'Private reviewer note', 'token' => 'secret-media-token']);
        $ownEvent = AuditLog::where('action', 'audio.rejected')->latest('id')->firstOrFail();
        app(Foundation::class)->audit($this->admin, 'audio.approved', $foreign);
        $foreignEvent = AuditLog::where('action', 'audio.approved')->latest('id')->firstOrFail();
        $this->actingAs($this->member)->get('/account/notifications')->assertOk()->assertSee('Recording rejected')->assertDontSee('Recording approved')->assertDontSee('Private reviewer note')->assertDontSee('secret-media-token')->assertDontSee('Secret customer')->assertDontSee('Secret recording');
        $this->get('/dashboard')->assertOk()->assertSee('Account notifications')->assertSee('Calling credit')->assertSee('Not configured')->assertSee('Recent call outcomes');
        $this->post('/account/notifications/read', ['through_id' => $foreignEvent->id])->assertNotFound();
        $this->post('/account/notifications/read', ['through_id' => $ownEvent->id])->assertRedirect();
        $this->assertSame(0, app(Notices::class)->summary($this->member->fresh())['unread']);
        $this->assertSame(1, app(Notices::class)->summary($this->colleague->fresh())['unread']);
        $this->post('/account/preferences', ['notification_badge' => 0, 'notice_read_through_id' => $foreignEvent->id])->assertRedirect();
        $this->assertFalse($this->member->fresh()->portal_preferences['notification_badge']);
        $this->assertSame($ownEvent->id, $this->member->fresh()->notice_read_through_id);
        $this->actingAs($this->admin)->get('/account/notifications')->assertForbidden();
    }

    public function test_known_client_failed_logins_are_auditable_without_credentials(): void
    {
        $this->post('/login', ['identifier' => $this->member->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', ['client_id' => $this->client->id, 'action' => 'identity.signin_failed', 'actor_id' => $this->member->id]);
        $this->assertStringNotContainsString('wrong-password', DB::table('audit_logs')->where('action', 'identity.signin_failed')->value('details'));
        $this->actingAs($this->admin)->get('/clients/'.$this->client->id.'?tab=activity&activity=logins')->assertOk()->assertSee('identity.signin_failed');
    }
}
