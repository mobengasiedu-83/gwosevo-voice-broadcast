<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reporting indexes keep per-attempt and ledger exports bounded as history grows.
        Schema::table('call_attempts', fn (Blueprint $t) => $t->index('ended_at'));
        Schema::table('call_ratings', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('wallet_ledger_entries', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('payment_intents', fn (Blueprint $t) => $t->index('credited_at'));
        Schema::table('billing_exceptions', fn (Blueprint $t) => $t->index('resolved_at'));

        Schema::create('billing_disputes', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->foreignId('wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $t->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->restrictOnDelete();
            $t->foreignId('call_attempt_id')->nullable()->constrained('call_attempts')->restrictOnDelete();
            $t->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $t->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('source', 16)->default('admin');
            $t->string('category', 24);
            $t->string('status', 24)->default('open');
            $t->string('subject', 160);
            $t->text('description');
            // The amount under dispute is optional; a resolution credit is separate.
            $t->unsignedBigInteger('amount_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->unsignedTinyInteger('minor_unit_scale')->nullable();
            $t->text('resolution')->nullable();
            $t->unsignedBigInteger('credit_amount_minor')->default(0);
            $t->foreignId('credit_ledger_entry_id')->nullable()->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'status']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Billing disputes and their resolution credits are financial records. Archive and review them before dropping this history.');
    }
};
