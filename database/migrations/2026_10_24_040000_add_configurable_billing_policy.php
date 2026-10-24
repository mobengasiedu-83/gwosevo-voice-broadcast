<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_versions', fn (Blueprint $t) => $t->unsignedInteger('reservation_seconds')->default(620));
        Schema::table('wallet_reservations', fn (Blueprint $t) => $t->unsignedInteger('verified_seconds')->nullable());
        Schema::table('call_attempts', fn (Blueprint $t) => $t->timestamp('playback_failed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn('playback_failed_at'));
        Schema::table('wallet_reservations', fn (Blueprint $t) => $t->dropColumn('verified_seconds'));
        Schema::table('rate_versions', fn (Blueprint $t) => $t->dropColumn('reservation_seconds'));
    }
};
