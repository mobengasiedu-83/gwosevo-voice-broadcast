<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * A client or administrator billing dispute. Core references and the disputed
 * amount are immutable; resolution adds an auditable credit entry when awarded.
 */
class BillingDispute extends Model
{
    use BelongsToClient;

    public const STATUSES = ['open', 'under_review', 'resolved', 'rejected'];

    public const CATEGORIES = ['charge', 'payment', 'adjustment', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'minor_unit_scale' => 'integer',
            'credit_amount_minor' => 'integer', 'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $dispute) => $dispute->public_id ??= (string) Str::uuid());
        static::updating(function (self $dispute) {
            if ($dispute->isDirty(['public_id', 'client_id', 'wallet_id', 'payment_intent_id', 'call_attempt_id',
                'wallet_ledger_entry_id', 'source', 'category', 'subject', 'description', 'amount_minor',
                'currency', 'minor_unit_scale', 'opened_by'])) {
                throw new LogicException('A billing dispute reference and subject are immutable. Open a new dispute to correct them.');
            }
            if (in_array($dispute->getOriginal('status'), ['resolved', 'rejected'], true)) {
                throw new LogicException('A resolved billing dispute is final.');
            }
        });
        static::deleting(fn () => throw new LogicException('Billing disputes are financial records and cannot be deleted.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function wallet()
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function payment()
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CallAttempt::class, 'call_attempt_id');
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'wallet_ledger_entry_id');
    }

    public function creditEntry()
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'credit_ledger_entry_id');
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'under_review'], true);
    }
}
