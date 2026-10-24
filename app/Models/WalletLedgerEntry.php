<?php

namespace App\Models;

use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('client_account', function ($query) {
            if (app(AccountContext::class)->id() !== null) {
                $query->whereIn('wallet_id', Wallet::select('id'));
            }
        });
        static::updating(fn () => throw new \LogicException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Use a compensating ledger entry.'));
    }
}
