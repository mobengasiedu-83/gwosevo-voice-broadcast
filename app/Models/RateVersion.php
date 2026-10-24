<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Create a new price version instead.'));
        static::deleting(fn () => throw new \LogicException('Price history cannot be deleted.'));
    }
}
