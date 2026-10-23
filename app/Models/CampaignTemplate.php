<?php

namespace App\Models;

use App\Models\Concerns\ClientOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignTemplate extends Model
{
    use ClientOwned;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['blueprint' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Template versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Template versions are immutable.'));
    }
}
