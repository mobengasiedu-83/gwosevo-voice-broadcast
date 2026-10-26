<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->unique()->constrained('clients')->restrictOnDelete();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            // Balances are integers in the currency's minor unit; never floats.
            $t->unsignedBigInteger('available_minor')->default(0);
            $t->unsignedBigInteger('reserved_minor')->default(0);
            $t->timestamps();
        });

        Schema::create('wallet_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // A unique attempt reservation prevents duplicate holds for one call.
            $t->foreignId('call_attempt_id')->unique()->constrained('call_attempts')->restrictOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            $t->string('state', 16)->default('held')->index();
            $t->unsignedBigInteger('settled_minor')->nullable();
            $t->unsignedBigInteger('shortfall_minor')->default(0);
            $t->string('settlement_key', 120)->nullable()->unique();
            $t->string('release_reason', 255)->nullable();
            $t->timestamp('reserved_at');
            $t->timestamp('settled_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->index(['wallet_id', 'state']);
            $t->index(['client_id', 'state']);
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->foreignId('call_attempt_id')->nullable()->constrained('call_attempts')->restrictOnDelete();
            $t->foreignId('wallet_reservation_id')->nullable()->constrained('wallet_reservations')->restrictOnDelete();
            $t->string('type', 24)->index();
            $t->bigInteger('available_delta_minor');
            $t->bigInteger('reserved_delta_minor')->default(0);
            $t->unsignedBigInteger('available_after_minor');
            $t->unsignedBigInteger('reserved_after_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_unit_scale');
            $t->string('reason', 255);
            $t->string('approval_reference', 255)->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 120)->unique();
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['wallet_id', 'id']);
            $t->index(['client_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Financial wallet history is append-only. Archive and review it before dropping these tables.');
    }
};
