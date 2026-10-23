<?php

namespace App\Models;

use App\Models\Concerns\ClientChild;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CampaignVersion extends Model
{
    use ClientChild;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Approved versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Approved versions are immutable.'));
    }
}
