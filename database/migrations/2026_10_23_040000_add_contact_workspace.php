<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_groups', function (Blueprint $t) {
            $t->text('description')->nullable();
            $t->string('category', 40)->default('General');
            $t->boolean('active')->default(true)->index();
        });
        Schema::create('contact_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('contact_group_id')->constrained();
            $t->string('filename');
            $t->string('status')->default('mapping')->index();
            $t->json('headers');
            $t->json('mapping')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('imported')->default(0);
            $t->unsignedInteger('duplicates')->default(0);
            $t->unsignedInteger('invalid')->default(0);
            $t->timestamps();
        });
        Schema::create('contact_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->string('name')->nullable();
            $t->string('phone_e164', 16)->nullable();
            $t->string('source')->nullable();
            $t->string('state')->default('staged');
            $t->string('error')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->unique(['contact_import_id', 'row_number']);
            $t->index(['contact_import_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_rows');
        Schema::dropIfExists('contact_imports');
        Schema::table('contact_groups', fn (Blueprint $t) => $t->dropColumn(['description', 'category', 'active']));
    }
};
