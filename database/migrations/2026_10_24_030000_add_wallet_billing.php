<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_versions', function (Blueprint $t) {
            $t->id();
            $t->string('plan_code', 80);
            $t->unsignedInteger('version');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_digits');
            $t->unsignedInteger('rate_minor_per_minute');
            $t->string('duration_basis', 20);
            $t->unsignedTinyInteger('increment_seconds');
            $t->string('playback_failure_policy', 20);
            $t->foreignId('created_by')->constrained('users');
            $t->text('evidence');
            $t->timestamp('created_at');
            $t->unique(['plan_code', 'version']);
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_account_id')->unique()->constrained();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_digits');
            $t->bigInteger('balance_minor')->default(0);
            $t->bigInteger('reserved_minor')->default(0);
            $t->foreignId('rate_version_id')->nullable()->constrained();
            $t->timestamps();
        });
        Schema::create('wallet_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_id')->constrained();
            $t->foreignId('call_attempt_id')->unique()->constrained();
            $t->bigInteger('amount_minor');
            $t->bigInteger('held_minor');
            $t->bigInteger('charged_minor')->default(0);
            $t->string('status', 20)->default('held')->index();
            $t->json('policy');
            $t->string('review_reason')->nullable();
            $t->text('evidence')->nullable();
            $t->timestamps();
            $t->index(['wallet_id', 'status']);
        });
        Schema::create('wallet_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_id')->constrained();
            $t->foreignId('call_attempt_id')->nullable()->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->foreignId('refund_entry_id')->nullable()->constrained('wallet_ledger_entries');
            $t->string('kind', 30);
            $t->bigInteger('amount_minor');
            $t->bigInteger('balance_after_minor');
            $t->string('idempotency_key', 120);
            $t->text('reason');
            $t->timestamp('created_at');
            $t->unique(['wallet_id', 'idempotency_key']);
            $t->index(['wallet_id', 'id']);
        });
        foreach (['wallet_ledger_entries', 'rate_versions'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = $table.'_immutable_'.strtolower($operation);
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Financial history is append-only'); END");
                } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial history is append-only'");
                }
            }
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallet_reservations');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('rate_versions');
    }
};
