<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trunk_profiles', function (Blueprint $t) {
            $t->string('name')->nullable()->unique();
            $t->string('caller_id', 32)->nullable();
            $t->unsignedInteger('max_channels')->default(0);
            $t->unsignedInteger('reserved_channels')->default(0);
            $t->unsignedInteger('safe_channels')->default(0);
            $t->decimal('max_cps', 8, 3)->default(0);
            $t->string('number_format')->default('international');
            $t->boolean('active')->default(false);
            $t->text('evidence')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->double('next_send_at')->default(0);
        });
        Schema::table('audio_files', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('name')->nullable();
            $t->string('storage_key')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->decimal('duration', 8, 3)->nullable();
            $t->string('status')->default('pending');
            $t->foreignId('uploaded_by')->nullable()->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('deployed_at')->nullable();
            $t->text('deployment_evidence')->nullable();
        });
        Schema::table('campaigns', function (Blueprint $t) {
            $t->foreignId('active_version_id')->nullable()->constrained('campaign_versions');
            $t->boolean('stop_requested')->default(false);
            $t->timestamp('last_dispatched_at', 6)->nullable()->index();
        });
        Schema::table('campaign_recipients', function (Blueprint $t) {
            $t->foreignId('campaign_id')->nullable()->constrained();
            $t->foreignId('contact_id')->nullable()->constrained();
            $t->string('phone_e164', 16)->nullable();
            $t->string('status')->default('pending');
            $t->string('exclusion_reason')->nullable();
            $t->unique(['campaign_id', 'contact_id']);
            $t->index(['campaign_id', 'status']);
        });
        Schema::table('call_attempts', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('campaign_recipient_id')->nullable()->constrained();
            $t->foreignId('campaign_version_id')->nullable()->constrained();
            $t->foreignId('trunk_profile_id')->nullable()->constrained();
            $t->string('action_id')->nullable()->unique();
            $t->string('uniqueid')->nullable()->index();
            $t->string('linkedid')->nullable()->index();
            $t->string('channel')->nullable();
            $t->string('state')->default('reserved')->index();
            $t->string('outcome')->nullable()->index();
            $t->string('token', 64)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('playback_started_at')->nullable();
            $t->timestamp('playback_completed_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('hangup_cause')->nullable();
            $t->unsignedInteger('billable_seconds')->nullable();
            $t->unsignedInteger('attempt_no')->default(1);
            $t->unique(['campaign_recipient_id', 'attempt_no']);
        });
        Schema::create('channel_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_attempt_id')->unique()->constrained();
            $t->foreignId('campaign_id')->constrained();
            $t->foreignId('trunk_profile_id')->constrained();
            $t->timestamp('acquired_at');
            $t->timestamp('released_at')->nullable()->index();
        });
        Schema::table('call_events', function (Blueprint $t) {
            $t->foreignId('call_attempt_id')->nullable()->constrained();
            $t->string('event_key', 64)->nullable()->unique();
            $t->string('event_type')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamp('processed_at')->nullable()->index();
        });
        Schema::create('broadcast_gateway', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->uuid('owner')->nullable();
            $t->timestamp('heartbeat_at')->nullable();
            $t->boolean('healthy')->default(false);
            $t->timestamp('snapshot_at')->nullable();
            $t->json('channels')->nullable();
            $t->double('next_send_at')->default(0);
            $t->boolean('emergency_stop')->default(false);
        });
        DB::table('broadcast_gateway')->insert(['id' => 1, 'healthy' => false, 'next_send_at' => 0, 'emergency_stop' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_gateway');
        Schema::table('call_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('call_attempt_id'));
        Schema::table('call_events', fn (Blueprint $t) => $t->dropColumn(['event_key', 'event_type', 'payload', 'occurred_at', 'processed_at']));
        Schema::dropIfExists('channel_reservations');
        Schema::table('call_attempts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('campaign_recipient_id');
            $t->dropConstrainedForeignId('campaign_version_id');
            $t->dropConstrainedForeignId('trunk_profile_id');
            $t->dropColumn(['uuid', 'action_id', 'uniqueid', 'linkedid', 'channel', 'state', 'outcome', 'token', 'sent_at', 'answered_at', 'playback_started_at', 'playback_completed_at', 'ended_at', 'hangup_cause', 'billable_seconds', 'attempt_no']);
        });
        Schema::table('campaign_recipients', function (Blueprint $t) {
            $t->dropConstrainedForeignId('campaign_id');
            $t->dropConstrainedForeignId('contact_id');
            $t->dropColumn(['phone_e164', 'status', 'exclusion_reason']);
        });
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('active_version_id');
            $t->dropColumn(['stop_requested', 'last_dispatched_at']);
        });
        Schema::table('audio_files', function (Blueprint $t) {
            $t->dropConstrainedForeignId('uploaded_by');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['uuid', 'name', 'storage_key', 'checksum', 'duration', 'status', 'approved_at', 'deployed_at', 'deployment_evidence']);
        });
        Schema::table('trunk_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('verified_by');
            $t->dropColumn(['name', 'caller_id', 'max_channels', 'reserved_channels', 'safe_channels', 'max_cps', 'number_format', 'active', 'evidence', 'verified_at', 'next_send_at']);
        });
    }
};
