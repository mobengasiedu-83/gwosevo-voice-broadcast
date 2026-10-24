<?php

namespace App\Models;

use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('client_account', function ($query) {
            if (($id = app(AccountContext::class)->id()) !== null) {
                $query->where('wallets.client_account_id', $id);
                if (! app(AccountContext::class)->active($id)) {
                    $query->whereRaw('1 = 0');
                }
            }
        });
        static::updating(function ($wallet) {
            if ($wallet->isDirty(['client_account_id', 'currency', 'minor_digits'])) {
                throw new \LogicException('Wallet account and denomination are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Wallets cannot be deleted.'));
    }
}
