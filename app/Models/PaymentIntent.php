<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A payment-gateway top-up attempt. Client credit is posted only after a
 * verified successful callback and is linked to exactly one ledger entry.
 */
class PaymentIntent extends Model
{
    public const STATUSES = ['pending', 'succeeded', 'failed', 'cancelled', 'refunded'];

    public const OPEN_STATUSES = ['pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'minor_unit_scale' => 'integer',
            'metadata' => 'array', 'expires_at' => 'datetime', 'verified_at' => 'datetime',
            'credited_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentIntent $intent) {
            if ($intent->isDirty(['public_id', 'client_id', 'wallet_id', 'provider', 'amount_minor', 'currency', 'minor_unit_scale', 'request_key'])) {
                throw new LogicException('A payment intent amount, account, provider and request key are immutable.');
            }
            if ($intent->isDirty('wallet_ledger_entry_id') && $intent->getOriginal('wallet_ledger_entry_id') !== null) {
                throw new LogicException('A payment credit link cannot be changed once set.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payment intents are financial records and cannot be deleted.'));
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function wallet()
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'wallet_ledger_entry_id');
    }

    public function creditReversal()
    {
        return $this->hasOne(WalletLedgerEntry::class, 'reversal_of_id', 'wallet_ledger_entry_id');
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function disputes()
    {
        return $this->hasMany(BillingDispute::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['succeeded', 'refunded'], true) && $this->credited_at !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'succeeded' => 'Paid',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => 'Awaiting confirmation',
        };
    }

    public function clientMessage(): string
    {
        return match ($this->status) {
            'succeeded' => 'Payment confirmed and credited to your calling wallet.',
            'failed' => 'The payment did not complete. No credit was applied.',
            'cancelled' => 'The payment was cancelled. No credit was applied.',
            'refunded' => 'Finance recorded a refund and reversed the original wallet credit.',
            default => 'We have not received a final confirmation from the payment provider yet.',
        };
    }

    public function receiptNumber(): string
    {
        return 'RCPT-'.strtoupper(substr(str_replace('-', '', $this->public_id), 0, 12));
    }
}
