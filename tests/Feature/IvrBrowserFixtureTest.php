<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IvrBrowserFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_isolated_rendered_designer_for_browser_checks(): void
    {
        $path = getenv('IVR_BROWSER_FIXTURE');
        if (! $path) {
            $this->markTestSkipped('Set IVR_BROWSER_FIXTURE to export a browser fixture.');
        }
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        if (getenv('IVR_BROWSER_CLIENT')) {
            $accounts = app(\App\Services\Clients\Accounts::class);
            $client = $accounts->create($user, 'Browser test client');
            $accounts->status($user, $client, 'active', 'Browser fixture');
            $member = User::factory()->create();
            $accounts->member($user, $client, $member);
            $user = $member;
        }
        $html = $this->actingAs($user)->get('/interactive')->assertOk()->getContent();
        file_put_contents($path, $html);
    }
}
