<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CallTimingEvidence extends Model
{
    use ScopedThroughClient;

    public $timestamps = false;

    protected $table = 'call_timing_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered' => 'boolean', 'duration_ms' => 'integer', 'billable_minutes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Timing evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Timing evidence cannot be deleted.'));
    }
}
