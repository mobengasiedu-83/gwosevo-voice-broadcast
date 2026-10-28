<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            foreach (['sent_at', 'answered_at', 'ended_at'] as $column) {
                $table->timestamp($column, 6)->nullable()->change();
            }
        });
        Schema::table('wallet_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('answer_paid_minor')->default(0);
            $table->unsignedBigInteger('answer_reserved_minor')->default(0);
        });
    }

    public function down(): void
    {
        throw new LogicException('Answer charges are financial history and must be retained.');
    }
};
