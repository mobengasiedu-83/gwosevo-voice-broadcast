<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('country', 2)->nullable();
            $t->timestamp('terms_accepted_at')->nullable();
            $t->string('terms_version', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['country', 'terms_accepted_at', 'terms_version']));
    }
};
