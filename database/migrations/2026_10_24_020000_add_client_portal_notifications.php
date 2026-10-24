<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 64)->unique();
            $t->string('kind');
            $t->string('title');
            $t->text('message');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['client_account_id', 'occurred_at']);
        });
        Schema::create('client_notice_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_notice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->unique(['client_notice_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notice_reads');
        Schema::dropIfExists('client_notices');
    }
};
