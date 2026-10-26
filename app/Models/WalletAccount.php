<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A client's prepaid calling account. Balances are always integer minor units.
 *
 * Only App\Services\Billing\Wallet may move balances; every movement must also
 * append a WalletLedgerEntry in the same database transaction.
 */
class WalletAccount extends Model
{
    protected $table = 'wallets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['available_minor' => 'integer', 'reserved_minor' => 'integer', 'minor_unit_scale' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function ($wallet) {
            if ($wallet->available_minor < 0 || $wallet->reserved_minor < 0) {
                throw new LogicException('Wallet balances cannot be negative.');
            }
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function reservations()
    {
        return $this->hasMany(WalletReservation::class, 'wallet_id');
    }

    public function entries()
    {
        return $this->hasMany(WalletLedgerEntry::class, 'wallet_id');
    }
}
