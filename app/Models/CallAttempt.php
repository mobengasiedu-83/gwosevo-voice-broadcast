<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use Illuminate\Database\Eloquent\Model;

class CallAttempt extends Model
{
    use ScopedThroughClient;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::updating(function ($attempt) {
            if ($attempt->isDirty('tariff_snapshot')) {
                throw new \LogicException('Attempt tariff snapshots are immutable.');
            }
        });
    }

    public function rating()
    {
        return $this->hasOne(CallRating::class);
    }

    public function timingEvidence()
    {
        return $this->hasOne(CallTimingEvidence::class);
    }

    public function billingExceptions()
    {
        return $this->hasMany(BillingException::class);
    }

    protected function casts(): array
    {
        return ['tariff_snapshot' => 'array', 'ivr_completed_at' => 'datetime', 'opted_out_at' => 'datetime', 'transfer_started_at' => 'datetime', 'transfer_answered_at' => 'datetime', 'sent_at' => 'datetime', 'answered_at' => 'datetime', 'playback_started_at' => 'datetime', 'playback_completed_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
