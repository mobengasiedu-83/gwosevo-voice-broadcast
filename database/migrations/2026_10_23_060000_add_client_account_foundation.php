<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_accounts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name');
            $t->string('status')->default('active')->index();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('suspended_at')->nullable();
            $t->text('suspension_reason')->nullable();
            $t->timestamps();
        });
        foreach (['users', 'contacts', 'contact_groups', 'audio_files', 'campaigns', 'contact_imports', 'campaign_templates', 'ivr_flows', 'audit_logs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('client_account_id')->nullable()->constrained('client_accounts')->restrictOnDelete();
            });
        }
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('account_scope')->default('internal');
            $t->dropUnique(['phone_e164']);
            $t->unique(['account_scope', 'phone_e164']);
        });
    }

    public function down(): void
    {
        // Rollback must never silently merge contacts belonging to different accounts.
        if (DB::table('contacts')->whereNotNull('phone_e164')->select('phone_e164')->groupBy('phone_e164')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Client contacts contain shared phone numbers. Export and resolve them before rolling back.');
        }
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropUnique(['account_scope', 'phone_e164']);
            $t->dropColumn('account_scope');
            $t->unique('phone_e164');
        });
        foreach (['audit_logs', 'ivr_flows', 'campaign_templates', 'contact_imports', 'campaigns', 'audio_files', 'contact_groups', 'contacts', 'users'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('client_account_id'));
        }
        Schema::dropIfExists('client_accounts');
    }
};
