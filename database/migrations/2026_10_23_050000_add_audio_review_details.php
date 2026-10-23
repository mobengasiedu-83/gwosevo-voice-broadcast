<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_files', function (Blueprint $t) {
            $t->foreignId('rejected_by')->nullable()->constrained('users');
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audio_files', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rejected_by');
            $t->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};
