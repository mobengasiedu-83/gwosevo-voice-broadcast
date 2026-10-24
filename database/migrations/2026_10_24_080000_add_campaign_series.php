<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtmf_responses', fn (Blueprint $t) => $t->string('response_type', 16)->default('digit'));
        Schema::create('campaign_series', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('template');
            $t->string('frequency', 10);
            $t->string('timezone', 64);
            $t->date('anchor_date');
            $t->date('ends_on');
            $t->string('local_time', 5);
            $t->timestamp('next_run_at')->index();
            $t->boolean('auto_start')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('campaign_occurrences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_series_id')->constrained('campaign_series')->restrictOnDelete();
            $t->timestamp('scheduled_for');
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 30);
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['campaign_series_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_occurrences');
        Schema::dropIfExists('campaign_series');
        Schema::table('dtmf_responses', fn (Blueprint $t) => $t->dropColumn('response_type'));
    }
};
