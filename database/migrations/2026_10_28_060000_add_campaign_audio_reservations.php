<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_reservations', function (Blueprint $t) {
            $t->unsignedBigInteger('call_attempt_id')->nullable()->change();
            $t->foreignId('campaign_version_id')->nullable()->constrained('campaign_versions')->restrictOnDelete();
            $t->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->restrictOnDelete();
            $t->json('tariff_snapshot')->nullable();
            $t->unique(['campaign_version_id', 'campaign_recipient_id'], 'wallet_campaign_recipient_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('wallet_reservations')->whereNull('call_attempt_id')->exists()) {
            throw new RuntimeException('Campaign reservation history must be retained; do not roll back this migration after use.');
        }
        Schema::table('wallet_reservations', function (Blueprint $t) {
            $t->dropUnique('wallet_campaign_recipient_unique');
            $t->dropForeign(['campaign_version_id']);
            $t->dropForeign(['campaign_recipient_id']);
            $t->dropColumn(['campaign_version_id', 'campaign_recipient_id', 'tariff_snapshot']);
            $t->unsignedBigInteger('call_attempt_id')->nullable(false)->change();
        });
    }
};
