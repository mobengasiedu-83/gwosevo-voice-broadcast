<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_creates_missing_delivery_table_and_preserves_notifications(): void
    {
        $user = User::factory()->create();
        $id = DB::table('user_notifications')->insertGetId([
            'user_id' => $user->id,
            'category' => 'system',
            'event_key' => 'migration.test',
            'title' => 'Existing notification',
        ]);
        Schema::drop('notification_deliveries');

        $migration = require database_path('migrations/2026_10_26_180000_add_notifications.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('notification_deliveries'));
        $this->assertDatabaseHas('user_notifications', ['id' => $id, 'title' => 'Existing notification']);
        DB::table('notification_deliveries')->insert([
            'notification_id' => $id,
            'channel' => 'email',
        ]);
        $migration->up();
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $id, 'channel' => 'email']);
    }
}
