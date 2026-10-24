<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notices', function (Blueprint $t) {
            $t->foreignId('recipient_user_id')->nullable()->constrained('users');
            $t->string('severity', 20)->default('info');
        });
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->boolean('email_enabled')->default(false);
            $t->boolean('sms_enabled')->default(false);
            $t->unsignedBigInteger('low_balance_minor')->default(0);
            $t->timestamp('channels_enabled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notification_signals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('signal_key', 100);
            $t->boolean('active')->default(false);
            $t->unsignedInteger('generation')->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'signal_key']);
        });
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_notice_id')->constrained('client_notices');
            $t->foreignId('user_id')->constrained();
            $t->string('channel', 10);
            $t->string('status', 20)->default('pending')->index();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('failure_reason')->nullable();
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['client_notice_id', 'user_id', 'channel']);
        });
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('client_account_id')->nullable()->constrained();
            $t->string('kind', 20);
            $t->string('format', 10);
            $t->json('filters');
            $t->string('timezone', 80);
            $t->string('frequency', 10);
            $t->string('local_time', 5);
            $t->unsignedSmallInteger('lookback_days');
            $t->boolean('active')->default(true);
            $t->timestamp('next_run_at')->index();
            $t->timestamps();
        });
        Schema::create('report_exports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('client_account_id')->nullable()->constrained();
            $t->foreignId('report_schedule_id')->nullable()->constrained();
            $t->timestamp('scheduled_for')->nullable();
            $t->string('kind', 20);
            $t->string('format', 10);
            $t->json('filters');
            $t->string('status', 20)->default('pending')->index();
            $t->string('storage_key')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->string('failure_reason')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->unique(['report_schedule_id', 'scheduled_for']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_signals');
        Schema::dropIfExists('notification_preferences');
        Schema::table('client_notices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recipient_user_id');
            $t->dropColumn('severity');
        });
    }
};
