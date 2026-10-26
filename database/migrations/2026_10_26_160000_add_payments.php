<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $t->string('provider', 32)->index();
            $t->string('provider_reference', 190)->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            $t->string('status', 16)->default('pending');
            $t->string('checkout_url', 2048)->nullable();
            $t->string('failure_code', 64)->nullable();
            $t->string('failure_message', 255)->nullable();
            $t->string('request_key', 120)->unique();
            $t->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('credited_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->string('refund_reference', 255)->nullable();
            $t->foreignId('wallet_ledger_entry_id')->nullable()->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_reference'], 'payment_intents_provider_reference_unique');
            $t->index(['client_id', 'status']);
            $t->index(['wallet_id', 'status']);
            $t->index(['provider', 'status']);
        });

        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('provider_event_id', 190);
            $t->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $t->string('event_type', 64);
            $t->string('status', 24);
            $t->string('reference', 36)->nullable();
            $t->string('provider_reference', 190)->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            $t->string('result', 40)->index();
            // Only non-sensitive reconciliation fields and a hash are exposed in
            // the admin console; the encrypted payload is retained for audit.
            $t->string('message', 255)->nullable();
            $t->char('payload_hash', 64);
            $t->text('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['provider', 'provider_event_id'], 'payment_events_provider_event_unique');
            $t->index(['payment_intent_id', 'id']);
            $t->index(['client_id', 'id']);
            $t->index(['result', 'id']);
        });

        Schema::create('payment_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32)->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 16);
            $t->unsignedInteger('intents_checked')->default(0);
            $t->unsignedInteger('credited_count')->default(0);
            $t->unsignedInteger('pending_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedInteger('refunded_count')->default(0);
            $t->unsignedInteger('issue_count')->default(0);
            $t->json('details')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Payment history is append-only. Archive and review it before dropping these tables.');
    }
};
