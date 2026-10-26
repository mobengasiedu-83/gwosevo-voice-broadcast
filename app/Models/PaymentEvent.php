<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Verified provider callback. Events are append-only and unique per provider
 * event id; their result records how reconciliation applied the notification.
 */
class PaymentEvent extends Model
{
    public const EXCEPTIONS = [
        'amount_mismatch', 'currency_mismatch', 'provider_reference_mismatch',
        'unknown_intent', 'unsupported_status', 'conflict', 'ignored_final', 'credit_failed',
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array', 'amount_minor' => 'integer',
            'minor_unit_scale' => 'integer', 'processed_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verified payment events are append-only.'));
        static::deleting(fn () => throw new LogicException('Verified payment events cannot be deleted.'));
    }

    public function intent()
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
