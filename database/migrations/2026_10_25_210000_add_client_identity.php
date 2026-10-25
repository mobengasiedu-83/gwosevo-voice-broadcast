<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['phone_e164' => 'string', 'phone_verified_at' => 'timestamp', 'auth_version' => 'integer', 'terms_accepted_at' => 'timestamp', 'terms_version' => 'string'] as $column => $type) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $t) use ($column, $type) {
                    if ($column === 'auth_version') {
                        $t->unsignedInteger($column)->default(0);
                    } elseif ($column === 'phone_e164') {
                        $t->string($column, 16)->nullable();
                    } else {
                        $t->$type($column)->nullable();
                    }
                });
            }
        }
        Schema::table('users', fn (Blueprint $t) => $t->string('email')->nullable()->change());
        if (! Schema::hasIndex('users', ['phone_e164'], 'unique')) {
            Schema::table('users', fn (Blueprint $t) => $t->unique('phone_e164'));
        }
        if (! Schema::hasColumn('clients', 'profile')) {
            Schema::table('clients', fn (Blueprint $t) => $t->json('profile')->nullable());
        }
        if (! Schema::hasTable('identity_challenges')) {
            Schema::create('identity_challenges', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('purpose', 20);
                $t->string('channel', 10);
                $t->text('identifier');
                $t->string('code_hash');
                $t->string('session_hash', 64);
                $t->text('details');
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('expires_at')->index();
                $t->timestamp('verified_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Identity records require a reviewed retention and rollback migration.');
    }
};
