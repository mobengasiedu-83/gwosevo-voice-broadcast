<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->timestamp('stop_requested_at')->nullable()->index());
        Schema::create('dashboard_telemetry', function (Blueprint $t) {
            $t->string('source')->primary();
            $t->string('status')->default('unavailable');
            $t->timestamp('observed_at')->nullable();
            $t->json('payload')->nullable();
        });
        Schema::create('dashboard_queue_members', function (Blueprint $t) {
            $t->id();
            $t->string('queue', 80);
            $t->string('interface', 120);
            $t->string('name')->nullable();
            $t->unsignedInteger('device_status')->default(0);
            $t->boolean('paused')->default(false);
            $t->boolean('in_call')->default(false);
            $t->unsignedInteger('calls_taken')->default(0);
            $t->unsignedBigInteger('last_call_epoch')->nullable();
            $t->unsignedInteger('wrapup_seconds')->nullable();
            $t->timestamp('observed_at');
            $t->unique(['queue', 'interface']);
        });
        Schema::create('dashboard_queues', function (Blueprint $t) {
            $t->string('queue', 80)->primary();
            $t->unsignedInteger('waiting')->default(0);
            $t->unsignedInteger('completed')->default(0);
            $t->unsignedInteger('abandoned')->default(0);
            $t->decimal('service_level', 6, 2)->nullable();
            $t->timestamp('observed_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn('stop_requested_at'));
        Schema::dropIfExists('dashboard_queues');
        Schema::dropIfExists('dashboard_queue_members');
        Schema::dropIfExists('dashboard_telemetry');
    }
};
