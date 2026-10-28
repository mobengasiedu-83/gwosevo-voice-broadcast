<?php

namespace App\Models;

use App\Models\Concerns\ScopedThroughClient;
use App\Services\Billing\TelecomUsage;
use Illuminate\Database\Eloquent\Model;

class CallAttempt extends Model
{
    use ScopedThroughClient;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::creating(function ($attempt) {
            $attempt->telecom_tariff_snapshot ??= app(TelecomUsage::class)->snapshot();
            $attempt->recording_duration_ms ??= $attempt->recordingDurationMs();
            if ($attempt->recording_duration_ms === null) {
                $snapshot = $attempt->approvedVersion?->snapshot['broadcast'] ?? [];
                $audio = AudioFile::find($snapshot['audio_id'] ?? 0);
                if ($audio && ! empty($snapshot['checksum']) && hash_equals($snapshot['checksum'], (string) $audio->checksum) && $audio->duration > 0) {
                    $attempt->recording_duration_ms = (int) ceil($audio->duration * 1000);
                }
            }
        });
        static::updating(function ($attempt) {
            if ($attempt->isDirty('recording_duration_ms') && $attempt->getOriginal('recording_duration_ms') !== null) {
                throw new \LogicException('Attempt recording lengths are immutable.');
            }
            if ($attempt->isDirty('telecom_tariff_snapshot')) {
                throw new \LogicException('Attempt carrier tariff snapshots are immutable.');
            }
            if ($attempt->isDirty('tariff_snapshot')) {
                throw new \LogicException('Attempt tariff snapshots are immutable.');
            }
        });
    }

    public function approvedVersion()
    {
        return $this->belongsTo(CampaignVersion::class, 'campaign_version_id');
    }

    public function recordingDurationMs(): ?int
    {
        $ms = $this->recording_duration_ms ?? $this->approvedVersion?->snapshot['broadcast']['audio_duration_ms'] ?? null;

        return is_numeric($ms) && (int) $ms > 0 && (int) $ms <= 86400000 ? (int) $ms : null;
    }

    public function recordingMinutes(): ?int
    {
        $ms = $this->recordingDurationMs();

        return $ms === null ? null : intdiv($ms + 59999, 60000);
    }

    public function recipient()
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    public function reservation()
    {
        return $this->hasOne(WalletReservation::class);
    }

    public function timeSpentMs(): int
    {
        if (! $this->answered_at) {
            return 0;
        }
        $end = $this->ended_at ?? now();

        return intdiv(max(0, (int) $end->format('Uu') - (int) $this->answered_at->format('Uu')) + 999, 1000);
    }

    public function rating()
    {
        return $this->hasOne(CallRating::class);
    }

    public function telecomUsage()
    {
        return $this->hasOne(CallTelecomUsage::class);
    }

    public function timingEvidence()
    {
        return $this->hasOne(CallTimingEvidence::class);
    }

    public function billingExceptions()
    {
        return $this->hasMany(BillingException::class);
    }

    public function disputes()
    {
        return $this->hasMany(BillingDispute::class);
    }

    protected function casts(): array
    {
        return ['telecom_tariff_snapshot' => 'array', 'recording_duration_ms' => 'integer', 'tariff_snapshot' => 'array', 'ivr_completed_at' => 'datetime', 'opted_out_at' => 'datetime', 'transfer_started_at' => 'datetime', 'transfer_answered_at' => 'datetime', 'sent_at' => 'datetime', 'answered_at' => 'datetime', 'playback_started_at' => 'datetime', 'playback_completed_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
