<?php

namespace App\Http\Controllers;

use App\Models\CallAttempt;
use App\Services\Billing\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CallDetailRecordController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('reports.view');
        $filters = $request->validate([
            'msisdn' => 'nullable|string|max:32|regex:/\A[+0-9]+\z/',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'campaign_id' => 'nullable|integer|min:1', 'answered' => 'nullable|in:0,1',
        ]);
        // The attempt's global account scope applies to the page AND the export.
        $query = CallAttempt::with(['recipient', 'rating', 'reservation', 'approvedVersion', 'telecomUsage'])
            ->when($filters['msisdn'] ?? null, fn ($q, $number) => $q->whereHas('recipient', fn ($r) => $r->where('phone_e164', $number)))
            ->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->whereHas('recipient', fn ($r) => $r->where('campaign_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('sent_at', '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('sent_at', '<', CarbonImmutable::parse($date)->addDay()))
            ->when(isset($filters['answered']), fn ($q) => $filters['answered'] === '1' ? $q->whereNotNull('answered_at') : $q->whereNull('answered_at'));
        if ($request->routeIs('cdr.export')) {
            return response()->streamDownload(function () use ($query) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['attempt_id', 'attempt_uuid', 'campaign_id', 'msisdn', 'state', 'outcome',
                    'sent_at_utc', 'answered_at_utc', 'ended_at_utc', 'time_spent_ms', 'time_spent_seconds',
                    'recording_duration_ms', 'billing_basis', 'billable_minutes', 'charge', 'paid', 'shortfall', 'currency', 'billing_status', 'telecom_duration_ms', 'telecom_billed_duration_ms', 'telecom_billed_minutes', 'telecom_cost', 'telecom_currency'], ',', '"', '');
                $query->orderBy('id')->chunkById(500, function ($attempts) use ($out) {
                    foreach ($attempts as $a) {
                        $r = $a->reservation;
                        $scale = $a->rating?->minor_unit_scale ?? $a->tariff_snapshot['minor_unit_scale'] ?? 2;
                        $charge = $a->rating?->amount_minor ?? ($a->answered_at ? ($a->recordingMinutes() ?? 0) * ($a->tariff_snapshot['rate_minor_per_minute'] ?? 0) : 0);
                        $paid = $r?->state === 'settled' ? $r->settled_minor - $r->shortfall_minor : ($r?->answer_paid_minor ?? 0);
                        $row = [$a->id, $a->uuid, $a->recipient?->campaign_id, $a->recipient?->phone_e164,
                            $a->state, $a->outcome, $a->sent_at?->utc()->format('Y-m-d H:i:s.u'),
                            $a->answered_at?->utc()->format('Y-m-d H:i:s.u'), $a->ended_at?->utc()->format('Y-m-d H:i:s.u'),
                            $a->timeSpentMs(), number_format($a->timeSpentMs() / 1000, 3, '.', ''),
                            $a->recordingDurationMs(), $a->rating?->policy_version ?? 'recording_minute_v1',
                            $a->rating?->billable_minutes ?? ($a->answered_at ? $a->recordingMinutes() : 0), Wallet::amount($charge, $scale),
                            Wallet::amount($paid, $scale), Wallet::amount(max(0, $charge - $paid), $scale),
                            $a->rating?->currency ?? $a->tariff_snapshot['currency'] ?? '',
                            $r?->state === 'settled' ? 'settled' : ($a->answered_at ? ($paid > 0 ? 'answer charged / pending settlement' : 'unpaid / pending settlement') : ($r?->state ?? 'unbilled')),
                            $a->telecomUsage?->duration_ms, $a->telecomUsage?->billed_duration_ms, $a->telecomUsage?->billed_minutes,
                            $a->telecomUsage?->cost_minor === null ? '' : Wallet::amount($a->telecomUsage->cost_minor, $a->telecomUsage->minor_unit_scale),
                            $a->telecomUsage?->currency];
                        fputcsv($out, array_map(fn ($v) => preg_match('/\A[=+\-@]/', (string) $v) ? "'".$v : $v, $row), ',', '"', '');
                    }
                });
                fclose($out);
            }, 'call-detail-records-'.now()->utc()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return response()->view('broadcast.cdr', ['attempts' => $query->orderByDesc('id')->paginate(50)->withQueryString(), 'filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
