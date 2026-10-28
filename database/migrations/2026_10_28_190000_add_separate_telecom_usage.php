<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->json('telecom_tariff_snapshot')->nullable());
        Schema::create('call_telecom_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_attempt_id')->unique()->constrained('call_attempts')->restrictOnDelete();
            $t->boolean('answered');
            $t->unsignedBigInteger('duration_ms');
            $t->unsignedBigInteger('billed_duration_ms');
            $t->unsignedInteger('billed_minutes');
            $t->unsignedBigInteger('cost_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->unsignedTinyInteger('minor_unit_scale')->nullable();
            $t->json('tariff_snapshot')->nullable();
            $t->string('timing_source', 80);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Telecom cost history must be retained.');
    }
};
