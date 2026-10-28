<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CallTelecomUsage extends Model
{
    use ScopedThroughClient;

    public $timestamps = false;

    protected $table = 'call_telecom_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered' => 'boolean', 'duration_ms' => 'integer', 'billed_minutes' => 'integer',
            'billed_duration_ms' => 'integer', 'cost_minor' => 'integer', 'tariff_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Final carrier usage is immutable.'));
        static::deleting(fn () => throw new LogicException('Carrier usage history must be retained.'));
    }
}
