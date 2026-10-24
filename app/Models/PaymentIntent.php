<?php

namespace App\Models;

use App\Services\Clients\AccountContext;
use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['callback_hash', 'merchant_id', 'checkout_url'];

    protected static function booted(): void
    {
        static::addGlobalScope('client_account', function ($q) {
            if (($id = app(AccountContext::class)->id()) !== null) {
                $q->where('payment_intents.client_account_id', $id);
                if (! app(AccountContext::class)->active($id)) {
                    $q->whereRaw('1 = 0');
                }
            }
        });
        static::updating(function ($intent) {
            if ($intent->isDirty(['uuid', 'client_account_id', 'wallet_id', 'created_by', 'request_key', 'provider', 'merchant_id', 'currency', 'amount_cents', 'credit_minor', 'wallet_digits', 'callback_hash'])) {
                throw new \LogicException('Payment identity and requested credit are immutable.');
            }
            foreach (['payment_code', 'transaction_id', 'ledger_entry_id', 'paid_at'] as $field) {
                if ($intent->getOriginal($field) !== null && $intent->isDirty($field)) {
                    throw new \LogicException('Verified payment bindings cannot be changed.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Payment history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'credit_minor' => 'integer', 'wallet_digits' => 'integer', 'verified_at' => 'datetime', 'paid_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
