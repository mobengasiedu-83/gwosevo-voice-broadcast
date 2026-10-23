<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait ClientOwned
{
    public static function bootClientOwned(): void
    {
        static::addGlobalScope('client_account', function (Builder $query) {
            $context = app(AccountContext::class);
            if (($id = $context->id()) !== null) {
                $query->where($query->getModel()->qualifyColumn('client_account_id'), $id);
                if (! $context->active($id)) {
                    $query->whereRaw('1 = 0');
                }
            }
        });
        static::creating(function ($record) {
            $actorField = match ($record->getTable()) {
                'contacts' => 'created_by', 'audio_files' => 'uploaded_by', 'audit_logs' => 'actor_id', default => 'owner_id',
            };
            $ownerAccount = $record->getAttribute($actorField)
                ? User::whereKey($record->getAttribute($actorField))->value('client_account_id') : null;
            $id = app(AccountContext::class)->id() ?? $ownerAccount ?? $record->client_account_id;
            if ($ownerAccount !== null && (int) $ownerAccount !== (int) $id) {
                throw new LogicException('Record owner and client account must match.');
            }
            if ($id !== null && $record->getTable() !== 'audit_logs') {
                abort_unless(app(AccountContext::class)->active((int) $id), 403, 'Client account is suspended.');
            }
            $record->client_account_id = $id;
            if ($record->getTable() === 'contacts') {
                $record->account_scope = $id === null ? 'internal' : 'client:'.$id;
            }
        });
        static::updating(function ($record) {
            if (($id = app(AccountContext::class)->id()) !== null) {
                abort_unless((int) $record->client_account_id === $id && app(AccountContext::class)->active($id), 403);
            }
            $ownerField = match ($record->getTable()) {
                'contacts' => 'created_by', 'audio_files' => 'uploaded_by', 'audit_logs' => 'actor_id', default => 'owner_id',
            };
            if ($record->isDirty($ownerField)) {
                $ownerAccount = $record->getAttribute($ownerField) ? User::whereKey($record->getAttribute($ownerField))->value('client_account_id') : null;
                abort_unless($ownerAccount === $record->client_account_id, 403, 'New owner must belong to the same client account.');
            }
            if ($record->isDirty('client_account_id') || ($record->getTable() === 'contacts' && $record->isDirty('account_scope'))) {
                throw new LogicException('Client ownership cannot be changed on an existing record.');
            }
        });
    }
}
