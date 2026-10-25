<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('name');
                $t->string('status')->default('pending')->index();
                $t->text('status_reason')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('client_memberships')) {
            Schema::create('client_memberships', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->constrained()->restrictOnDelete();
                // One account per login prevents ambiguous tenant selection.
                $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
                $t->string('role')->default('member');
                $t->string('status')->default('active');
                $t->timestamps();
            });
        }
        // Some development databases contain an earlier account schema from
        // another checkout. Preserve its explicit ownership, never infer it.
        if (Schema::hasTable('client_accounts')) {
            foreach (DB::table('client_accounts')->orderBy('id')->get() as $old) {
                $status = in_array($old->status, ['pending', 'active', 'suspended', 'closed'], true) ? $old->status : 'pending';
                if (($old->launch_blocked ?? false) || ($old->dispatch_blocked ?? false)) {
                    $status = 'suspended';
                }
                DB::table('clients')->insertOrIgnore(['id' => $old->id, 'uuid' => $old->uuid, 'name' => $old->name, 'status' => $status, 'status_reason' => $old->suspension_reason ?? null, 'created_at' => $old->created_at, 'updated_at' => $old->updated_at]);
                $new = DB::table('clients')->where('id', $old->id)->first();
                if (! $new || $new->uuid !== $old->uuid) {
                    throw new LogicException('Legacy account ID conflict requires a reviewed ownership mapping.');
                }
            }
            if (Schema::hasColumn('users', 'client_account_id')) {
                foreach (DB::table('users')->whereNotNull('client_account_id')->orderBy('id')->get() as $user) {
                    $existing = DB::table('client_memberships')->where('user_id', $user->id)->first();
                    if ($existing && (int) $existing->client_id !== (int) $user->client_account_id) {
                        throw new LogicException('Legacy membership conflicts with the account boundary.');
                    }
                    DB::table('client_memberships')->insertOrIgnore(['client_id' => $user->client_account_id, 'user_id' => $user->id, 'role' => ($user->client_role ?? '') === 'owner' ? 'owner' : 'member', 'status' => ($user->client_access_enabled ?? true) ? 'active' : 'revoked', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        foreach (['contacts', 'contact_groups', 'contact_imports', 'campaigns', 'audio_files', 'ivr_flows', 'campaign_templates', 'audit_logs', 'suppression_entries'] as $table) {
            if (! Schema::hasColumn($table, 'client_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->foreignId('client_id')->nullable()->constrained()->restrictOnDelete());
            }
            if (Schema::hasColumn($table, 'client_account_id')) {
                DB::table($table)->whereNull('client_id')->whereNotNull('client_account_id')->update(['client_id' => DB::raw('client_account_id')]);
            }
        }
        foreach (['contacts', 'suppression_entries'] as $table) {
            if (! Schema::hasColumn($table, 'ownership_key')) {
                // Zero retains internal ownership and avoids NULL uniqueness differences.
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('ownership_key')->default(0));
            }
            DB::table($table)->update(['ownership_key' => DB::raw('COALESCE(client_id, 0)')]);
            // Build the replacement before dropping any old uniqueness boundary.
            if (! Schema::hasIndex($table, ['ownership_key', 'phone_e164'], 'unique')) {
                Schema::table($table, fn (Blueprint $t) => $t->unique(['ownership_key', 'phone_e164']));
            }
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['unique'] && in_array('phone_e164', $index['columns'], true) && $index['columns'] !== ['ownership_key', 'phone_e164']) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropUnique($index['name']));
                }
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Client ownership migration requires a reviewed data migration to roll back.');
    }
};
