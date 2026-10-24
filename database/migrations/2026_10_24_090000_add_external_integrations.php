<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_plans', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->unsignedInteger('requests_per_minute'); $t->unsignedInteger('requests_per_day');
            $t->unsignedInteger('import_rows_per_day'); $t->unsignedInteger('drafts_per_day'); $t->unsignedInteger('webhook_limit'); $t->timestamps();
        });
        Schema::table('client_accounts', fn (Blueprint $t) => $t->foreignId('commercial_plan_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('api_credentials', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->string('name', 100); $t->string('token_hash', 64); $t->json('scopes');
            $t->timestamp('expires_at'); $t->timestamp('revoked_at')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamps();
        });
        Schema::create('integration_usage', function (Blueprint $t) {
            $t->id(); $t->foreignId('client_account_id')->constrained()->cascadeOnDelete(); $t->string('bucket', 32); $t->string('metric', 32); $t->unsignedBigInteger('used')->default(0); $t->timestamps();
            $t->unique(['client_account_id', 'bucket', 'metric']);
        });
        Schema::create('api_idempotency', function (Blueprint $t) {
            $t->id(); $t->foreignId('api_credential_id')->constrained()->cascadeOnDelete(); $t->string('key_hash', 64); $t->string('request_hash', 64);
            $t->unsignedSmallInteger('response_status')->nullable(); $t->longText('response_body')->nullable(); $t->timestamp('expires_at'); $t->timestamps();
            $t->unique(['api_credential_id', 'key_hash']);
        });
        Schema::create('webhook_subscriptions', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->string('url', 1000); $t->text('signing_secret'); $t->json('events'); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('webhook_subscription_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 100); $t->longText('payload'); $t->string('status', 16)->default('pending'); $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('http_status')->nullable(); $t->string('failure_reason')->nullable(); $t->timestamp('next_attempt_at')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
            $t->unique(['webhook_subscription_id', 'event_key']); $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries'); Schema::dropIfExists('webhook_subscriptions'); Schema::dropIfExists('api_idempotency'); Schema::dropIfExists('integration_usage'); Schema::dropIfExists('api_credentials');
        Schema::table('client_accounts', fn (Blueprint $t) => $t->dropConstrainedForeignId('commercial_plan_id'));
        Schema::dropIfExists('commercial_plans');
    }
};
