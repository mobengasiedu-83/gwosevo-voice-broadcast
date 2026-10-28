<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A credit hold for one campaign recipient, allocated to its first call attempt. Once settled or released a
 * reservation is final; settlement may happen at most once per attempt.
 */
class WalletReservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tariff_snapshot' => 'array', 'amount_minor' => 'integer', 'settled_minor' => 'integer', 'shortfall_minor' => 'integer',
            'minor_unit_scale' => 'integer', 'reserved_at' => 'datetime', 'settled_at' => 'datetime', 'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Wallet reservation history cannot be deleted.'));
    }

    public function wallet()
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CallAttempt::class, 'call_attempt_id');
    }
}
