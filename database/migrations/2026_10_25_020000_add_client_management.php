<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $t) {
            $t->json('profile')->nullable();
            $t->json('settings')->nullable();
            $t->foreignId('account_manager_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('business_verification')->default('unverified');
            $t->text('verification_note')->nullable();
            $t->timestamp('business_verified_at')->nullable();
            $t->boolean('launch_blocked')->default(false);
            $t->boolean('dispatch_blocked')->default(false);
            $t->boolean('require_both_contacts')->default(false);
            $t->boolean('require_business_verification')->default(false);
            $t->unsignedInteger('max_active_campaigns')->nullable();
            $t->unsignedBigInteger('daily_spend_limit_minor')->nullable();
            $t->text('restriction_reason')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('client_role', 30)->nullable();
            $t->boolean('client_access_enabled')->default(true);
            $t->timestamp('last_login_at')->nullable();
        });
        // Preserve prior operational access; designate the first account member as owner.
        DB::table('users')->whereNotNull('client_account_id')->update(['client_role' => 'administrator']);
        DB::table('client_accounts')->orderBy('id')->chunkById(200, function ($accounts) {
            foreach ($accounts as $account) {
                $id = DB::table('users')->where('client_account_id', $account->id)->min('id');
                if ($id) {
                    DB::table('users')->where('id', $id)->update(['client_role' => 'owner']);
                }
            }
        });
        Schema::create('client_documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->string('storage_key');
            $t->string('checksum', 64);
            $t->string('mime', 80);
            $t->date('expires_on')->nullable();
            $t->string('status', 20)->default('pending');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('client_invitations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->string('email')->index();
            $t->string('name');
            $t->string('role', 30);
            $t->string('token_hash', 64);
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('client_documents')->exists() || DB::table('client_invitations')->exists()) {
            throw new RuntimeException('Archive client documents and invitations before rolling back their schema.');
        }
        Schema::dropIfExists('client_invitations');
        Schema::dropIfExists('client_documents');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['client_role', 'client_access_enabled', 'last_login_at']));
        Schema::table('client_accounts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_manager_id');
            $t->dropColumn(['profile', 'settings', 'business_verification', 'verification_note', 'business_verified_at', 'launch_blocked', 'dispatch_blocked', 'require_both_contacts', 'require_business_verification', 'max_active_campaigns', 'daily_spend_limit_minor', 'restriction_reason']);
        });
    }
};
