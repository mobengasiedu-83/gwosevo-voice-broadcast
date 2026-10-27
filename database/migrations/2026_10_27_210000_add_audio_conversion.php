<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_files', function (Blueprint $table) {
            $table->string('source_key')->nullable();
            $table->string('original_name')->nullable();
            $table->text('processing_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audio_files', fn (Blueprint $table) => $table->dropColumn(['source_key', 'original_name', 'processing_error']));
    }
};
