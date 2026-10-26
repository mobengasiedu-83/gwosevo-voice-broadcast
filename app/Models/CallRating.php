<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CallRating extends Model
{
    use ScopedThroughClient;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tariff_snapshot' => 'array', 'duration_ms' => 'integer', 'billable_minutes' => 'integer', 'amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Final call ratings are immutable.'));
        static::deleting(fn () => throw new LogicException('Final call ratings cannot be deleted.'));
    }
}
