<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->index(['sent_at', 'ended_at']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->index(['owner_id', 'status']));
        Schema::table('call_events', fn (Blueprint $t) => $t->index(['call_attempt_id', 'event_type', 'id']));
    }

    public function down(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropIndex(['sent_at', 'ended_at']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropIndex(['owner_id', 'status']));
        Schema::table('call_events', fn (Blueprint $t) => $t->dropIndex(['call_attempt_id', 'event_type', 'id']));
    }
};
