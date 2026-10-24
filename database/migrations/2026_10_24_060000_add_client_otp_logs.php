<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_otp_logs', function (Blueprint $t) {
            $t->id();
            // Keep history after expired authentication challenges are pruned.
            $t->uuid('challenge_id')->index();
            $t->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('purpose', 40);
            $t->string('channel', 20);
            $t->string('recipient_hint', 4);
            $t->string('status', 30);
            $t->unsignedInteger('verification_attempts')->default(0);
            $t->timestamp('requested_at');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_otp_logs');
    }
};
