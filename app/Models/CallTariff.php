<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CallTariff extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Tariff versions are immutable. Create another version.'));
        static::deleting(fn () => throw new LogicException('Tariff history cannot be deleted.'));
    }
}
