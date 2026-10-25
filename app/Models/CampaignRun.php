<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignRun extends Model
{
    use ScopedThroughClient;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Run approval snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Run approval snapshots are immutable.'));
    }
}
