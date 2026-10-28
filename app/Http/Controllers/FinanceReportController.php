<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Billing\FinanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Module 15 administrator finance report, reconciliation verdict and CSV export. */
class FinanceReportController extends Controller
{
    public function index(Request $request, FinanceReport $report)
    {
        Gate::authorize('billing.manage');
        $input = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'client_id' => 'nullable|integer|exists:clients,id',
            'currency' => 'nullable|string|regex:/\A[A-Z]{3}\z/',
        ]);
        $filters = $report->normalize($input);
        $rows = $report->rows($filters);
        $summary = $report->summary($filters);
        $clientRows = $report->clients($filters);
        $clients = Client::orderBy('name')->get(['id', 'name']);

        return response()->view('billing.finance', compact('input', 'rows', 'summary', 'clientRows', 'clients'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, FinanceReport $report)
    {
        Gate::authorize('billing.manage');
        $input = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'client_id' => 'nullable|integer|exists:clients,id',
            'currency' => 'nullable|string|regex:/\A[A-Z]{3}\z/',
        ]);
        $filters = $report->normalize($input);
        $filename = 'finance-attempts-'.$filters['from']->toDateString().'-to-'.$filters['to']->toDateString().'.csv';

        return response()->streamDownload(function () use ($report, $filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['client', 'campaign', 'attempt_uuid', 'answered_at', 'ended_at', 'duration_ms',
                'billable_minutes', 'rated_minutes', 'charge_minor', 'currency', 'minor_unit_scale',
                'reservation_state', 'settled_minor', 'shortfall_minor', 'settlement_entry_id', 'rated_at', 'telecom_duration_ms', 'telecom_billed_minutes', 'telecom_cost_minor', 'telecom_currency'], ',', '"', '');
            foreach ($report->exportRows($filters) as $row) {
                fputcsv($out, [
                    $this->cell($row->client_name), $this->cell($row->campaign_name), $this->cell($row->attempt_uuid),
                    $this->date($row->answered_at), $this->date($row->ended_at),
                    $row->duration_ms ?? 0, $row->evidence_minutes ?? 0, $row->rated_minutes ?? 0,
                    $row->amount_minor ?? 0, $row->rating_currency ?? $row->wallet_currency ?? '',
                    $row->rating_scale ?? $row->wallet_scale ?? 0, $this->cell($row->reservation_state),
                    $row->settled_minor ?? 0, $row->shortfall_minor ?? 0, $row->settlement_entry_id ?? '', $this->date($row->rated_at), $row->telecom_duration_ms ?? '', $row->telecom_billed_minutes ?? '', $row->telecom_cost_minor ?? '', $row->telecom_currency ?? '',
                ], ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function date($value): string
    {
        return $value ? CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s') : '';
    }

    private function cell($value): string
    {
        $value = (string) ($value ?? '');

        // Prevent spreadsheet formula execution from imported client names.
        return preg_match('/\A[=+\-@]/', $value) ? "'".$value : $value;
    }
}
