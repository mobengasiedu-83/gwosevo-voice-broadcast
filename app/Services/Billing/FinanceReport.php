<?php

namespace App\Services\Billing;

use App\Models\BillingDispute;
use App\Models\WalletAccount;
use App\Services\Payments\Payments;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only finance reporting over attempts, ratings, reservations, payments and
 * the append-only wallet ledger. It never changes a balance.
 */
class FinanceReport
{
    public function __construct(private Wallet $wallets, private Payments $payments) {}

    public function normalize(array $input): array
    {
        $from = CarbonImmutable::parse($input['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($input['to'] ?? now()->toDateString())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [
            'from' => $from, 'to' => $to,
            'client_id' => isset($input['client_id']) && $input['client_id'] !== '' ? (int) $input['client_id'] : null,
            'currency' => $input['currency'] ?? null,
        ];
    }

    public function rows(array $filters): LengthAwarePaginator
    {
        return $this->base($filters)
            ->select($this->columns())
            ->orderByDesc('a.ended_at')->orderByDesc('a.id')
            ->paginate(50)->withQueryString();
    }

    public function exportRows(array $filters): Generator
    {
        foreach ($this->base($filters)->select($this->columns())->orderBy('a.id')->cursor() as $row) {
            yield $row;
        }
    }

    public function clients(array $filters)
    {
        $rows = $this->base($filters)
            ->select(['cl.id as client_id', 'cl.name as client_name', 'w.currency', 'w.minor_unit_scale',
                'w.available_minor', 'w.reserved_minor'])
            ->selectRaw('COUNT(*) as attempts,
                SUM(CASE WHEN a.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered,
                COALESCE(SUM(e.duration_ms), 0) as duration_ms,
                COALESCE(SUM(r.billable_minutes), 0) as billable_minutes,
                COALESCE(SUM(r.amount_minor), 0) as charge_minor,
                COALESCE(SUM(wr.settled_minor), 0) as settled_minor,
                COALESCE(SUM(wr.shortfall_minor), 0) as shortfall_minor')
            ->groupBy('cl.id', 'cl.name', 'w.currency', 'w.minor_unit_scale', 'w.available_minor', 'w.reserved_minor')
            ->orderBy('cl.name')->get();
        $disputes = BillingDispute::query()->whereIn('status', ['open', 'under_review'])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->selectRaw('client_id, COUNT(*) as total')->groupBy('client_id')->pluck('total', 'client_id');
        $rows->each(fn ($row) => $row->open_disputes = (int) ($disputes[$row->client_id] ?? 0));

        return $rows;
    }

    public function summary(array $filters): array
    {
        $buckets = [];
        $attempts = $this->base($filters)
            ->selectRaw("COALESCE(w.currency, '') as currency, COALESCE(w.minor_unit_scale, 0) as minor_unit_scale,
                COUNT(*) as attempts,
                SUM(CASE WHEN a.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered,
                COALESCE(SUM(e.duration_ms), 0) as duration_ms,
                COALESCE(SUM(r.billable_minutes), 0) as billable_minutes,
                COALESCE(SUM(r.amount_minor), 0) as charge_minor,
                COALESCE(SUM(wr.settled_minor), 0) as settled_minor,
                COALESCE(SUM(wr.shortfall_minor), 0) as shortfall_minor")
            ->groupBy('w.currency', 'w.minor_unit_scale')->get();
        foreach ($attempts as $row) {
            $key = $this->bucket($buckets, $row->currency, $row->minor_unit_scale);
            $buckets[$key] = array_merge($buckets[$key], [
                'attempts' => (int) $row->attempts, 'answered' => (int) $row->answered,
                'duration_ms' => (int) $row->duration_ms, 'billable_minutes' => (int) $row->billable_minutes,
                'charge_minor' => (int) $row->charge_minor, 'settled_minor' => (int) $row->settled_minor,
                'shortfall_minor' => (int) $row->shortfall_minor,
            ]);
        }

        $ledger = DB::table('wallet_ledger_entries')->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->selectRaw("currency, minor_unit_scale,
                COALESCE(SUM(CASE WHEN type = 'funding' AND available_delta_minor > 0 THEN available_delta_minor ELSE 0 END), 0) as funding_minor,
                COALESCE(SUM(CASE WHEN type = 'adjustment' THEN available_delta_minor ELSE 0 END), 0) as adjustment_minor,
                COALESCE(SUM(CASE WHEN type IN ('settlement', 'answer_charge') THEN -(available_delta_minor + reserved_delta_minor) ELSE 0 END), 0) as debit_minor,
                COALESCE(SUM(CASE WHEN type = 'reversal' THEN available_delta_minor ELSE 0 END), 0) as reversal_minor")
            ->groupBy('currency', 'minor_unit_scale')->get();
        foreach ($ledger as $row) {
            $key = $this->bucket($buckets, $row->currency, $row->minor_unit_scale);
            $buckets[$key] = array_merge($buckets[$key], [
                'funding_minor' => (int) $row->funding_minor, 'adjustment_minor' => (int) $row->adjustment_minor,
                'debit_minor' => (int) $row->debit_minor, 'reversal_minor' => (int) $row->reversal_minor,
            ]);
        }

        $payments = DB::table('payment_intents')->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->selectRaw("currency, minor_unit_scale,
                COALESCE(SUM(CASE WHEN status IN ('succeeded','refunded') THEN amount_minor ELSE 0 END), 0) as payments_minor,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount_minor ELSE 0 END), 0) as refunds_minor,
                SUM(CASE WHEN status IN ('succeeded','refunded') THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status IN ('pending','failed','cancelled') THEN 1 ELSE 0 END) as unconfirmed_count")
            ->groupBy('currency', 'minor_unit_scale')->get();
        foreach ($payments as $row) {
            $key = $this->bucket($buckets, $row->currency, $row->minor_unit_scale);
            $buckets[$key] = array_merge($buckets[$key], [
                'payments_minor' => (int) $row->payments_minor, 'refunds_minor' => (int) $row->refunds_minor,
                'paid_count' => (int) $row->paid_count, 'unconfirmed_count' => (int) $row->unconfirmed_count,
            ]);
        }

        $balances = DB::table('wallets')->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->selectRaw('currency, minor_unit_scale, COUNT(*) as wallets, COALESCE(SUM(available_minor), 0) as available_minor, COALESCE(SUM(reserved_minor), 0) as reserved_minor')
            ->groupBy('currency', 'minor_unit_scale')->get();
        foreach ($balances as $row) {
            $key = $this->bucket($buckets, $row->currency, $row->minor_unit_scale);
            $buckets[$key] = array_merge($buckets[$key], [
                'wallets' => (int) $row->wallets, 'available_minor' => (int) $row->available_minor,
                'reserved_minor' => (int) $row->reserved_minor,
            ]);
        }

        $carrierExpenses = (clone $this->base($filters))->whereNotNull('u.currency')
            ->selectRaw('u.currency, u.minor_unit_scale, SUM(u.cost_minor) as cost_minor, SUM(u.billed_minutes) as billed_minutes')
            ->groupBy('u.currency', 'u.minor_unit_scale')->get();

        return array_merge([
            'carrier_expenses' => $carrierExpenses,
            'period' => ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()],
            'currencies' => array_values($buckets),
            'attempts' => array_sum(array_column($buckets, 'attempts')),
            'answered' => array_sum(array_column($buckets, 'answered')),
            'duration_ms' => array_sum(array_column($buckets, 'duration_ms')),
            'billable_minutes' => array_sum(array_column($buckets, 'billable_minutes')),
        ], $this->reconciliation($filters));
    }

    public function reconciliation(array $filters): array
    {
        $walletIssues = [];
        $checked = 0;
        $wallets = WalletAccount::with('client:id,name')
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->get();
        foreach ($wallets as $wallet) {
            $checked++;
            $verification = $this->wallets->verify($wallet);
            if (! $verification['ok']) {
                $walletIssues[] = [
                    'client_id' => $wallet->client_id, 'client' => $wallet->client?->name,
                    'issues' => $verification['issues'],
                ];
            }
        }

        return [
            'wallets_checked' => $checked,
            'wallet_issues' => $walletIssues,
            'payment_reconciliation' => $this->payments->reconcile(null, false),
            'unresolved_exceptions' => DB::table('billing_exceptions')->whereNull('resolved_at')
                ->when($filters['client_id'] ?? null, function ($q, $id) {
                    $q->whereIn('call_attempt_id', DB::table('call_attempts as a')
                        ->join('campaign_recipients as cr', 'cr.id', '=', 'a.campaign_recipient_id')
                        ->join('campaigns as c', 'c.id', '=', 'cr.campaign_id')
                        ->where('c.client_id', $id)->select('a.id'));
                })->count(),
            'open_disputes' => BillingDispute::whereIn('status', ['open', 'under_review'])
                ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))->count(),
        ];
    }

    private function base(array $filters): Builder
    {
        return DB::table('call_attempts as a')
            ->join('campaign_recipients as cr', 'cr.id', '=', 'a.campaign_recipient_id')
            ->join('campaigns as camp', 'camp.id', '=', 'cr.campaign_id')
            ->join('clients as cl', 'cl.id', '=', 'camp.client_id')
            ->leftJoin('call_timing_evidence as e', 'e.call_attempt_id', '=', 'a.id')
            ->leftJoin('call_telecom_usage as u', 'u.call_attempt_id', '=', 'a.id')
            ->leftJoin('call_ratings as r', 'r.call_attempt_id', '=', 'a.id')
            ->leftJoin('wallet_reservations as wr', 'wr.call_attempt_id', '=', 'a.id')
            ->leftJoin('wallets as w', 'w.client_id', '=', 'cl.id')
            ->leftJoin('wallet_ledger_entries as se', function ($join) {
                $join->on('se.wallet_reservation_id', '=', 'wr.id')->where('se.type', '=', 'settlement');
            })
            ->whereNotNull('a.ended_at')
            ->whereBetween('a.ended_at', [$filters['from'], $filters['to']])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('cl.id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('w.currency', $currency));
    }

    private function columns(): array
    {
        return ['cl.id as client_id', 'cl.name as client_name', 'camp.name as campaign_name',
            'u.duration_ms as telecom_duration_ms', 'u.billed_minutes as telecom_billed_minutes', 'u.cost_minor as telecom_cost_minor', 'u.currency as telecom_currency', 'u.minor_unit_scale as telecom_scale',
            'a.id as attempt_id', 'a.uuid as attempt_uuid', 'a.answered_at', 'a.ended_at', 'a.state', 'a.outcome',
            'w.currency as wallet_currency', 'w.minor_unit_scale as wallet_scale',
            'e.duration_ms', 'e.billable_minutes as evidence_minutes',
            'r.billable_minutes as rated_minutes', 'r.amount_minor', 'r.currency as rating_currency',
            'r.minor_unit_scale as rating_scale', 'r.created_at as rated_at',
            'wr.state as reservation_state', 'wr.settled_minor', 'wr.shortfall_minor', 'se.id as settlement_entry_id'];
    }

    private function bucket(array &$buckets, ?string $currency, $scale): string
    {
        $currency = $currency ?: '—';
        $scale = (int) ($scale ?? 0);
        $key = $currency.'|'.$scale;
        $buckets[$key] ??= [
            'currency' => $currency, 'minor_unit_scale' => $scale,
            'attempts' => 0, 'answered' => 0, 'duration_ms' => 0, 'billable_minutes' => 0,
            'charge_minor' => 0, 'settled_minor' => 0, 'shortfall_minor' => 0,
            'funding_minor' => 0, 'adjustment_minor' => 0, 'debit_minor' => 0, 'reversal_minor' => 0,
            'payments_minor' => 0, 'refunds_minor' => 0, 'paid_count' => 0, 'unconfirmed_count' => 0,
            'wallets' => 0, 'available_minor' => 0, 'reserved_minor' => 0,
        ];

        return $key;
    }
}
