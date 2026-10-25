<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 100)->unique();
            $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->string('type', 40);
            $t->text('payload')->nullable();
            $t->timestamp('occurred_at')->index();
            $t->index(['client_account_id', 'id']);
        });
        Schema::table('webhook_subscriptions', function (Blueprint $t) {
            $t->unsignedBigInteger('last_event_id')->default(0);
        });
        Schema::table('api_idempotency', fn (Blueprint $t) => $t->index('expires_at'));
        Schema::table('integration_usage', fn (Blueprint $t) => $t->index('bucket'));
        Schema::table('webhook_deliveries', function (Blueprint $t) {
            $t->index('created_at');
            $t->timestamp('redacted_at')->nullable();
        });
        Schema::table('campaigns', fn (Blueprint $t) => $t->index(['client_account_id', 'id']));
        Schema::table('call_attempts', fn (Blueprint $t) => $t->index(['state', 'ended_at']));
    }

    public function down(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropIndex(['state', 'ended_at']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropIndex(['client_account_id', 'id']));
        Schema::table('api_idempotency', fn (Blueprint $t) => $t->dropIndex(['expires_at']));
        Schema::table('integration_usage', fn (Blueprint $t) => $t->dropIndex(['bucket']));
        Schema::table('webhook_deliveries', function (Blueprint $t) {
            $t->dropIndex(['created_at']);
            $t->dropColumn('redacted_at');
        });
        Schema::table('webhook_subscriptions', fn (Blueprint $t) => $t->dropColumn('last_event_id'));
        Schema::dropIfExists('integration_events');
    }
};
