<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IvrFlow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'approved_at' => 'datetime', 'deployed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($flow) {
            if ($flow->getOriginal('status') === 'approved' && $flow->isDirty(['status', 'approved_by', 'approved_at', 'graph', 'family_uuid', 'uuid', 'checksum', 'version', 'name', 'owner_id'])) {
                throw new \LogicException('Approved flows are immutable; clone a new version.');
            }
        });
        static::deleting(function ($flow) {
            if ($flow->status === 'approved') {
                throw new \LogicException('Approved flows cannot be deleted.');
            }
        });
    }
}
