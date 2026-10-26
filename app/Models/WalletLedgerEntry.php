<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only financial ledger. Corrections are new reversing entries, never
 * updates or deletes, so a wallet's full history can always be reconstructed.
 */
class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'available_delta_minor' => 'integer', 'reserved_delta_minor' => 'integer',
            'available_after_minor' => 'integer', 'reserved_after_minor' => 'integer',
            'minor_unit_scale' => 'integer', 'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wallet ledger entries are append-only. Post a reversing entry instead.'));
        static::deleting(fn () => throw new LogicException('Wallet ledger entries cannot be deleted. Post a reversing entry instead.'));
    }

    public function wallet()
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CallAttempt::class, 'call_attempt_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
