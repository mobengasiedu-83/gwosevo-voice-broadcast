<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email')->nullable()->change();
            $t->string('phone_e164', 20)->nullable()->unique();
            $t->timestamp('phone_verified_at')->nullable();
        });
        Schema::create('client_auth_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('purpose', 20);
            $t->string('channel', 10);
            $t->string('identifier')->index();
            $t->string('secret_hash', 64);
            $t->string('code_hash')->nullable();
            $t->json('details')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('sent_at');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Phone-only accounts exist. Provide verified emails before rollback.');
        }
        Schema::dropIfExists('client_auth_challenges');
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['phone_e164']);
            $t->dropColumn(['phone_e164', 'phone_verified_at']);
            $t->string('email')->nullable(false)->change();
        });
    }
};
