<?php

namespace App\Models;

use App\Models\Concerns\ClientOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    use ClientOwned;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit records are append only.'));
        static::deleting(fn () => throw new LogicException('Audit records are append only.'));
    }
}
