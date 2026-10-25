<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Clients\Tenancy;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope('client', function (Builder $query) {
            $id = app(Tenancy::class)->current();
            if ($id !== null) {
                $query->where($query->getModel()->qualifyColumn('client_id'), $id);
            }
        });
        static::creating(function ($record) {
            $tenant = app(Tenancy::class);
            $id = $tenant->current();
            // Services can supply an actor without an authenticated HTTP request.
            $actorId = $record->owner_id ?? $record->uploaded_by ?? $record->created_by ?? $record->actor_id;
            $actorClient = $actorId ? $tenant->clientId(User::find($actorId)) : null;
            $expected = $id ?? $actorClient;
            if ($expected !== null) {
                abort_if($record->client_id !== null && (int) $record->client_id !== $expected, 403);
                $record->client_id = $expected;
            }
            if ($record->getTable() === 'contacts') {
                $record->ownership_key = $record->client_id ?? 0;
            }
        });
        static::saving(function ($record) {
            $id = app(Tenancy::class)->current();
            if (! $record->exists && $record->client_id === null && $id !== null) {
                $record->client_id = $id;
            }
            abort_if($id !== null && (int) $record->client_id !== $id, 403);
        });
        static::deleting(function ($record) {
            $id = app(Tenancy::class)->current();
            abort_if($id !== null && (int) $record->client_id !== $id, 403);
        });
        static::updating(function ($record) {
            abort_if($record->isDirty('client_id'), 403, 'Record account ownership is immutable.');
            if ($record->getTable() === 'contacts') {
                $record->ownership_key = $record->client_id ?? 0;
            }
        });
    }
}
