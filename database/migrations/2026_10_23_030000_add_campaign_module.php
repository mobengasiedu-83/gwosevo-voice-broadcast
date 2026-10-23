<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable()->index();
            $t->unsignedBigInteger('source_campaign_id')->nullable();
            $t->unsignedBigInteger('template_version_id')->nullable();
            $t->json('submitted_snapshot')->nullable();
        });
        Schema::create('campaign_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('family_uuid');
            $t->unsignedInteger('version');
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name');
            $t->json('blueprint');
            $t->timestamp('created_at');
            $t->unique(['family_uuid', 'version']);
            $t->index(['owner_id', 'name']);
        });
        Schema::create('campaign_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('campaign_id')->unique()->constrained();
            $t->foreignId('campaign_version_id')->constrained();
            $t->foreignId('started_by')->nullable()->constrained('users');
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_runs');
        Schema::dropIfExists('campaign_templates');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['archived_at', 'source_campaign_id', 'template_version_id', 'submitted_snapshot']));
    }
};
