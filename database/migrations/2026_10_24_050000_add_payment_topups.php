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
            $t->uuid('uuid')->unique();
            $t->foreignId('client_account_id')->constrained();
            $t->foreignId('wallet_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('request_key', 100);
            $t->string('provider', 20);
            $t->string('merchant_id', 100);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('credit_minor');
            $t->unsignedTinyInteger('wallet_digits');
            $t->string('status', 30)->default('initiating')->index();
            $t->string('payment_code', 120)->nullable()->unique();
            $t->string('transaction_id', 120)->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->string('callback_hash', 64);
            $t->foreignId('ledger_entry_id')->nullable()->unique()->constrained('wallet_ledger_entries');
            $t->string('review_reason')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('last_checked_at')->nullable()->index();
            $t->timestamps();
            $t->unique(['client_account_id', 'request_key']);
            $t->index(['wallet_id', 'created_at']);
        });
        Schema::create('payment_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_intent_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('source', 20);
            $t->string('result', 40);
            $t->string('evidence_hash', 64)->nullable();
            $t->timestamp('created_at');
            $t->index(['payment_intent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_checks');
        Schema::dropIfExists('payment_intents');
    }
};
