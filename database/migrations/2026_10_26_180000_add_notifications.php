<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 16: personal notification inbox plus observable per-channel delivery.
 *
 * One row per recipient keeps bell counts and read state user-specific. A
 * notification may be an in-app alert, a delivery-only alert for events that
 * already appear in the audit-backed client inbox, or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('category', 24);
            $table->string('event_key', 80);
            $table->string('severity', 16)->default('info');
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->json('data')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->boolean('in_app')->default(true);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'id']);
            $table->index(['client_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel']);
            $table->index(['channel', 'status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notifications');
    }
};
