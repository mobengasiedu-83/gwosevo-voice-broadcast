<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('name')->nullable();
            $t->string('phone_e164', 16)->nullable()->unique();
            $t->string('source_phone')->nullable();
            $t->string('source')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
        });
        Schema::table('contact_groups', function (Blueprint $t) {
            $t->string('name')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users');
        });
        Schema::create('contact_contact_group', function (Blueprint $t) {
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $t->primary(['contact_id', 'contact_group_id']);
        });
        Schema::create('contact_consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contact_id')->constrained();
            $t->string('purpose');
            $t->string('legal_basis');
            $t->string('source');
            $t->string('evidence_ref');
            $t->timestamp('granted_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['contact_id', 'purpose']);
        });
        Schema::create('suppression_entries', function (Blueprint $t) {
            $t->id();
            $t->string('phone_e164', 16)->unique();
            $t->string('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('effective_at');
        });
        Schema::table('campaigns', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('name')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->string('status')->default('draft')->index();
            $t->string('type')->default('one_way');
            $t->string('purpose')->nullable();
            $t->string('timezone')->default('Africa/Accra');
            $t->json('configuration')->nullable();
        });
        Schema::create('campaign_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamp('approved_at');
            $t->unique(['campaign_id', 'version']);
        });
        Schema::create('campaign_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('decision');
            $t->text('reason');
            $t->timestamp('created_at');
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action')->index();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('details');
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'campaign_reviews', 'campaign_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropConstrainedForeignId('owner_id'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['uuid', 'name', 'status', 'type', 'purpose', 'timezone', 'configuration']));
        Schema::dropIfExists('suppression_entries');
        Schema::dropIfExists('contact_consents');
        Schema::dropIfExists('contact_contact_group');
        Schema::table('contact_groups', fn (Blueprint $t) => $t->dropConstrainedForeignId('owner_id'));
        Schema::table('contact_groups', fn (Blueprint $t) => $t->dropColumn('name'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropConstrainedForeignId('created_by'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['uuid', 'name', 'phone_e164', 'source_phone', 'source']));
    }
};
