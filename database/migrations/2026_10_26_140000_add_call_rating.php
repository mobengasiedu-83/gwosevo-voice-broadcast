<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_tariffs', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80);
            $t->unsignedInteger('version');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            $t->unsignedBigInteger('rate_minor_per_minute');
            $t->string('playback_policy', 32);
            $t->foreignId('created_by')->constrained('users');
            $t->string('evidence', 1000);
            $t->timestamp('created_at');
            $t->unique(['code', 'version']);
        });
        Schema::create('client_tariffs', function (Blueprint $t) {
            $t->foreignId('client_id')->primary()->constrained('clients');
            $t->foreignId('call_tariff_id')->constrained('call_tariffs');
            $t->timestamp('updated_at');
        });
        Schema::table('call_attempts', fn (Blueprint $t) => $t->json('tariff_snapshot')->nullable());
        Schema::create('call_timing_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_attempt_id')->unique()->constrained();
            $t->string('source', 80);
            $t->string('reference', 1000);
            $t->string('payload_hash', 64);
            $t->boolean('answered');
            $t->string('disposition', 16);
            $t->string('answer_timestamp', 64)->nullable();
            $t->string('end_timestamp', 64);
            $t->unsignedBigInteger('duration_ms');
            $t->unsignedInteger('billable_minutes');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('call_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_attempt_id')->unique()->constrained();
            $t->foreignId('call_timing_evidence_id')->constrained('call_timing_evidence');
            $t->json('tariff_snapshot')->nullable();
            $t->string('policy_version', 32);
            $t->unsignedBigInteger('duration_ms');
            $t->unsignedInteger('billable_minutes');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3)->nullable();
            $t->unsignedTinyInteger('minor_unit_scale')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('billing_exceptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_attempt_id')->constrained();
            $t->string('issue', 64);
            $t->string('evidence', 1000)->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users');
            $t->timestamp('created_at');
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->unique(['call_attempt_id', 'issue']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Rating and tariff history require a reviewed archival migration; automatic rollback would destroy evidence.');
    }
};
