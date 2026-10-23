<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function add(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    public function up(): void
    {
        // MariaDB DDL commits incrementally. Each additive step is safe to resume after interruption.
        $this->add('trunk_profiles', 'allowed_caller_ids', fn (Blueprint $t) => $t->json('allowed_caller_ids')->nullable());
        $this->add('campaigns', 'scheduled_at', fn (Blueprint $t) => $t->timestamp('scheduled_at')->nullable()->index());
        $this->add('campaigns', 'priority', fn (Blueprint $t) => $t->unsignedInteger('priority')->default(1));
        $this->add('campaigns', 'dispatch_score', fn (Blueprint $t) => $t->double('dispatch_score')->default(0));
        $this->add('campaign_recipients', 'next_attempt_at', fn (Blueprint $t) => $t->timestamp('next_attempt_at')->nullable()->index());
        foreach (['ivr_completed_at', 'opted_out_at', 'transfer_started_at', 'transfer_answered_at'] as $column) {
            $this->add('call_attempts', $column, fn (Blueprint $t) => $t->timestamp($column)->nullable());
        }
        if (! Schema::hasTable('transfer_routes')) {
            Schema::create('transfer_routes', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('type');
                $t->string('destination', 80);
                $t->boolean('active')->default(true);
                $t->text('evidence');
                $t->foreignId('verified_by')->constrained('users');
                $t->timestamps();
            });
        }
        $this->add('ivr_flows', 'uuid', fn (Blueprint $t) => $t->uuid('uuid')->nullable());
        $this->add('ivr_flows', 'name', fn (Blueprint $t) => $t->string('name')->nullable());
        $this->add('ivr_flows', 'family_uuid', fn (Blueprint $t) => $t->uuid('family_uuid')->nullable());
        $this->add('ivr_flows', 'version', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        $this->add('ivr_flows', 'graph', fn (Blueprint $t) => $t->json('graph')->nullable());
        $this->add('ivr_flows', 'checksum', fn (Blueprint $t) => $t->string('checksum', 64)->nullable());
        $this->add('ivr_flows', 'status', fn (Blueprint $t) => $t->string('status')->default('draft'));
        $this->add('ivr_flows', 'owner_id', fn (Blueprint $t) => $t->foreignId('owner_id')->nullable()->constrained('users'));
        $this->add('ivr_flows', 'approved_by', fn (Blueprint $t) => $t->foreignId('approved_by')->nullable()->constrained('users'));
        foreach (['approved_at', 'deployed_at'] as $column) {
            $this->add('ivr_flows', $column, fn (Blueprint $t) => $t->timestamp($column)->nullable());
        }
        $this->add('ivr_flows', 'deployment_evidence', fn (Blueprint $t) => $t->text('deployment_evidence')->nullable());
        if (! Schema::hasIndex('ivr_flows', 'ivr_flows_uuid_unique')) {
            Schema::table('ivr_flows', fn (Blueprint $t) => $t->unique('uuid'));
        }
        if (! Schema::hasIndex('ivr_flows', 'ivr_flows_family_uuid_version_unique')) {
            Schema::table('ivr_flows', fn (Blueprint $t) => $t->unique(['family_uuid', 'version']));
        }
        $this->add('dtmf_responses', 'call_attempt_id', fn (Blueprint $t) => $t->foreignId('call_attempt_id')->nullable()->constrained());
        $this->add('dtmf_responses', 'node_id', fn (Blueprint $t) => $t->string('node_id', 32)->nullable());
        $this->add('dtmf_responses', 'digit', fn (Blueprint $t) => $t->string('digit', 1)->nullable());
        $this->add('dtmf_responses', 'event_key', fn (Blueprint $t) => $t->string('event_key', 64)->nullable()->unique());
        $this->add('dtmf_responses', 'occurred_at', fn (Blueprint $t) => $t->timestamp('occurred_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('dtmf_responses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('call_attempt_id');
            $t->dropColumn(['node_id', 'digit', 'event_key', 'occurred_at']);
        });
        Schema::table('ivr_flows', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_id');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['uuid', 'name', 'family_uuid', 'version', 'graph', 'checksum', 'status', 'approved_at', 'deployed_at', 'deployment_evidence']);
        });
        Schema::dropIfExists('transfer_routes');
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn(['ivr_completed_at', 'opted_out_at', 'transfer_started_at', 'transfer_answered_at']));
        Schema::table('campaign_recipients', fn (Blueprint $t) => $t->dropColumn('next_attempt_at'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['scheduled_at', 'priority', 'dispatch_score']));
        Schema::table('trunk_profiles', fn (Blueprint $t) => $t->dropColumn('allowed_caller_ids'));
    }
};
