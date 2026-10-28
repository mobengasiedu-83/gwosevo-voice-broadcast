<?php

namespace App\Services\Billing;

use App\Models\AudioFile;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\ContactGroup;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\Suppression;

/**
 * Read-only forecast of what one attempt to every eligible recipient would cost.
 *
 * One-way campaign launch reserves this cost across the eligible audience.
 * Dispatch consumes those holds without reserving twice. Unanswered calls release
 * their holds; retries reserve another recording-cost hold when actually dispatched.
 * Interactive calls retain the configured maximum-duration exposure.
 *
 * Consent evidence is recorded but is not a dialing gate (see
 * docs/campaign-audio-workflow.md); DND/suppression and deleted contacts are.
 * The consent count is reported so the client can see coverage without the
 * eligibility number claiming a rule the dispatcher does not enforce.
 */
class CampaignEstimate
{
    /** Readiness::snapshot() rejects audiences above this size. */
    public const MAX_AUDIENCE = 10000;

    /**
     * @param  array{source_group_id?: mixed, contact_ids?: mixed, audio_id?: mixed, type?: mixed, ivr_flow_id?: mixed}  $input
     * @return array<string, mixed>
     */
    public function preview(User $user, array $input): array
    {
        $clientId = app(Tenancy::class)->clientId($user);
        $audience = $this->audience($user, $clientId, $input);

        $contacts = $audience['ids'] === []
            ? collect()
            : Contact::whereIn('id', $audience['ids'])->get(['id', 'phone_e164']);

        $phones = $contacts->pluck('phone_e164')->filter()->unique()->values();
        $suppressedPhones = $phones->isEmpty()
            ? collect()
            : app(Suppression::class)->forAccount($clientId)
                ->whereIn('phone_e164', $phones->all())
                ->where('effective_at', '<=', now())
                ->pluck('phone_e164')
                ->flip();

        $suppressed = $contacts->filter(fn ($contact) => isset($suppressedPhones[$contact->phone_e164]))->count();
        $eligible = max(0, $contacts->count() - $suppressed);
        $unavailable = max(0, count($audience['ids']) - $contacts->count());

        $consents = $contacts->isEmpty() ? 0 : ContactConsent::whereIn('contact_id', $contacts->pluck('id'))
            ->whereNull('revoked_at')
            ->where('granted_at', '<=', now())
            ->distinct()
            ->count('contact_id');

        $audio = empty($input['audio_id']) ? null : AudioFile::find((int) $input['audio_id']);
        $duration = $audio?->duration;

        $tariff = $clientId === null ? null : app(Tariffs::class)->snapshot($clientId);
        $wallet = $clientId === null ? null : WalletAccount::where('client_id', $clientId)->first();
        $pricing = app(CampaignPricing::class)->calculate($audio?->id, $duration, $tariff, $eligible);
        $minutes = $pricing['billable_minutes'];
        $rate = $pricing['rate_minor_per_minute'];
        $currency = $tariff['currency'] ?? $wallet?->currency;
        $scale = $tariff !== null ? (int) $tariff['minor_unit_scale'] : $wallet?->minor_unit_scale;
        $comparable = $tariff !== null && $wallet !== null
            && $tariff['currency'] === $wallet->currency
            && (int) $tariff['minor_unit_scale'] === $wallet->minor_unit_scale;

        $priced = $minutes !== null && $tariff !== null && $rate > 0;
        $perCall = $pricing['rate_per_contact_minor'];
        $total = $pricing['estimated_campaign_amount_minor'];
        $available = $wallet?->available_minor;
        $additional = $total === null || ! $comparable ? null : max(0, $total - $available);
        $covered = $additional === null || $perCall < 1 ? null : intdiv($available, $perCall);

        $reason = match (true) {
            $audience['requested'] === 0 || $contacts->isEmpty() => 'no_audience',
            $minutes === null => 'audio_missing',
            $clientId === null => 'internal_campaign',
            $wallet === null => 'wallet_missing',
            $tariff === null => 'tariff_missing',
            ! $comparable => 'currency_mismatch',
            ! $priced => 'tariff_not_priced',
            $eligible === 0 => 'no_eligible',
            default => null,
        };

        $estimate = [
            'billing_snapshot' => $pricing + ['total_contact_count' => $contacts->count(), 'excluded_contact_count' => $suppressed],
            'group_total' => $audience['group_total'],
            'audience_total' => $contacts->count(),
            'requested_total' => $audience['requested'],
            'over_limit' => $audience['over_limit'],
            'eligible' => $eligible,
            'suppressed' => $suppressed,
            'unavailable' => $unavailable,
            'consent_on_record' => (int) $consents,
            'duration_seconds' => $duration,
            'duration_human_label' => $duration === null ? null : sprintf('%dm %ss', intdiv((int) $duration, 60), rtrim(rtrim(number_format(fmod($duration, 60), 3, '.', ''), '0'), '.')),
            'duration_label' => $this->durationLabel($duration),
            'minutes_per_call' => $minutes,
            'rate_minor_per_minute' => $rate,
            'per_call_minor' => $perCall,
            'total_minor' => $total,
            'available_minor' => $available,
            'additional_minor' => $additional,
            'covered_calls' => $covered,
            'currency' => $currency,
            'wallet_currency' => $wallet?->currency,
            'minor_unit_scale' => $scale,
            'billing_applicable' => $clientId !== null,
            'affordable' => $additional === null ? null : $additional === 0,
            'reason' => $reason,
            'messages' => [],
        ];

        $oneWay = ($input['type'] ?? 'one_way') === 'one_way';
        // A selected recording with no measured length refuses admission (0) rather
        // than quoting the maximum-call exposure; no recording defers to the
        // audio_missing explanation instead of claiming a credit refusal.
        $durationMs = $oneWay && $audio !== null ? (int) ceil(((float) $duration) * 1000) : null;
        $admission = app(Wallet::class)->admission($clientId, $tariff, $durationMs, $oneWay ? $eligible : 1);
        $estimate['can_start_call'] = $admission['allowed'];
        $estimate['call_hold_minor'] = $oneWay ? $perCall : $admission['exposure_minor'];
        $estimate['campaign_hold_minor'] = $oneWay ? $total : null;
        $estimate['admission_reason'] = $admission['reason'];

        $estimate['messages'] = $this->messages($estimate, $reason, $input);

        return $estimate;
    }

    public static function money(?int $minor, ?int $scale): string
    {
        if ($minor === null || $scale === null) {
            return '0';
        }

        $scale = max(0, min(6, $scale));

        return number_format((float) Wallet::amount($minor, $scale), $scale, '.', ',');
    }

    /**
     * Resolve the audience exactly as Workspace::save() does: explicit IDs plus
     * the selected active group, deduplicated. Foreign or deleted records are
     * filtered by the tenant scope and counted as unavailable.
     *
     * @return array{ids: array<int, int>, group_total: int, requested: int, over_limit: bool}
     */
    private function audience(User $user, ?int $clientId, array $input): array
    {
        $ids = collect(explode(',', (string) ($input['contact_ids'] ?? '')))
            ->map(fn ($value) => trim($value))
            ->filter(fn ($value) => $value !== '' && ctype_digit($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        $groupTotal = 0;
        if (! empty($input['source_group_id'])) {
            $group = ContactGroup::query()
                ->when($clientId === null && ! $user->isSuperAdministrator(), fn ($query) => $query->where('owner_id', $user->id))
                ->where('active', true)
                ->find((int) $input['source_group_id']);
            if ($group !== null) {
                $members = $group->contacts()->pluck('contacts.id')->map(fn ($id) => (int) $id)->unique()->values();
                $groupTotal = $members->count();
                $ids = $ids->merge($members)->unique()->values();
            }
        }

        $requested = $ids->count();

        return [
            'ids' => $ids->take(self::MAX_AUDIENCE)->values()->all(),
            'group_total' => $groupTotal,
            'requested' => $requested,
            'over_limit' => $requested > self::MAX_AUDIENCE,
        ];
    }

    private function durationLabel(?float $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $total = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * @return array<int, array{tone: string, text: string}>
     */
    private function messages(array $estimate, ?string $reason, array $input): array
    {
        $messages = [];

        if ($estimate['over_limit']) {
            $messages[] = ['tone' => 'warning', 'text' => sprintf(
                'The audience has %s contacts. A campaign can hold %s recipients, so this forecast covers the first %s. Reduce the group before saving.',
                number_format($estimate['requested_total']),
                number_format(self::MAX_AUDIENCE),
                number_format(self::MAX_AUDIENCE),
            )];
        }

        $explanation = match ($reason) {
            'no_audience' => 'Choose a contact group or add contact IDs to forecast credit.',
            'audio_missing' => 'Select an approved recording to price one attempt per contact.',
            'internal_campaign' => 'Internal campaign: calls are not billed to a client wallet. Audience eligibility is shown for reference.',
            'wallet_missing' => 'This account has no wallet. Finance must open and fund one before calls can be admitted.',
            'tariff_missing' => 'No approved call tariff is assigned. Admission will refuse calls until Finance assigns a rate plan.',
            'currency_mismatch' => 'The wallet currency or minor-unit scale does not match the assigned tariff. Finance must reconcile it before launch.',
            'tariff_not_priced' => 'The assigned tariff has no positive per-minute rate.',
            'no_eligible' => 'No contacts are eligible. Every selected contact is on the DND/suppression list or no longer exists.',
            default => null,
        };

        if ($explanation !== null) {
            $messages[] = [
                'tone' => in_array($reason, ['no_audience', 'audio_missing', 'internal_campaign'], true) ? 'info' : 'warning',
                'text' => $explanation,
            ];
        }

        if (($input['type'] ?? 'one_way') === 'interactive') {
            $messages[] = ['tone' => 'info', 'text' => 'Interactive campaigns can play extra IVR prompts. Billing uses the selected recording’s full length once the call is answered, regardless of talk time or extra prompts.'];
        }

        if ($estimate['additional_minor'] !== null && $estimate['additional_minor'] > 0) {
            $messages[] = ['tone' => 'warning', 'text' => sprintf(
                'Available credit covers %s of %s one-attempt calls. Add %s %s to call every eligible contact once.',
                number_format((int) $estimate['covered_calls']),
                number_format($estimate['eligible']),
                self::money($estimate['additional_minor'], $estimate['minor_unit_scale']),
                $estimate['currency'] ?? '',
            )];
        }

        if ($estimate['billing_applicable'] && ! $estimate['can_start_call']) {
            $oneWay = ($input['type'] ?? 'one_way') === 'one_way';
            $messages[] = ['tone' => 'warning', 'text' => 'New calls cannot start: '
                .($oneWay && $estimate['admission_reason'] === 'insufficient_credit' && $estimate['campaign_hold_minor'] !== null
                    ? 'available credit is below the '.self::money($estimate['campaign_hold_minor'], $estimate['minor_unit_scale']).' '
                    .$estimate['currency'].' recording cost for the eligible audience.'
                    : Wallet::clientMessage((string) $estimate['admission_reason']))];
        } elseif ($estimate['billing_applicable'] && $estimate['call_hold_minor'] > 0) {
            $oneWay = ($input['type'] ?? 'one_way') === 'one_way';
            $messages[] = ['tone' => 'info', 'text' => $oneWay
                ? 'Each call holds only '.self::money($estimate['call_hold_minor'], $estimate['minor_unit_scale']).' '.$estimate['currency']
                .' for its '.number_format((int) $estimate['minutes_per_call']).'-minute recording. The full '
                .self::money($estimate['campaign_hold_minor'], $estimate['minor_unit_scale']).' '.$estimate['currency']
                .' is reserved across the eligible audience when the campaign starts.'
                : 'Each new call temporarily requires '.self::money($estimate['call_hold_minor'], $estimate['minor_unit_scale']).' '.$estimate['currency']
                .' for its maximum call hold, even if the recording costs less.'];
        }

        return $messages;
    }
}
