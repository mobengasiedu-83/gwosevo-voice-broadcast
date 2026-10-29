<?php

namespace App\Http\Controllers;

use App\Models\CallTariff;
use App\Models\Client;
use App\Services\Billing\Tariffs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Module 15 rate-plan versions and client tariff assignments. */
class RatePlanController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate([
            'q' => 'nullable|string|max:80',
            'currency' => 'nullable|string|regex:/\A[A-Z]{3}\z/',
        ]);
        $tariffs = CallTariff::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('code', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('version', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))
            ->orderByDesc('id')->paginate(25, ['*'], 'tariffs_page')->withQueryString();
        $assignments = DB::table('client_tariffs as ct')
            ->join('clients as cl', 'cl.id', '=', 'ct.client_id')
            ->join('call_tariffs as t', 't.id', '=', 'ct.call_tariff_id')
            ->leftJoin('wallets as w', 'w.client_id', '=', 'cl.id')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('cl.name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('t.code', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['currency'] ?? null, fn ($q, $currency) => $q->where('t.currency', $currency))
            ->select(['ct.client_id', 'cl.name as client_name', 'cl.status', 't.id as tariff_id', 't.code',
                't.version', 't.currency', 't.minor_unit_scale', 't.rate_minor_per_minute', 't.playback_policy',
                'ct.updated_at', 'w.currency as wallet_currency', 'w.minor_unit_scale as wallet_scale',
                'w.available_minor'])
            ->orderBy('cl.name')->paginate(50, ['*'], 'assignments_page')->withQueryString();
        $clients = Client::withDisplayEmail()->orderBy('name')->limit(500)->get(['id', 'name', 'status']);
        $tariffOptions = CallTariff::orderBy('code')->orderByDesc('version')->get();
        $stats = [
            'versions' => CallTariff::count(),
            'assigned' => DB::table('client_tariffs')->count(),
            'unassigned' => Client::whereNotIn('id', DB::table('client_tariffs')->select('client_id'))->count(),
            'mismatches' => DB::table('client_tariffs as ct')
                ->join('call_tariffs as t', 't.id', '=', 'ct.call_tariff_id')
                ->join('wallets as w', 'w.client_id', '=', 'ct.client_id')
                ->where(fn ($q) => $q->whereColumn('w.currency', '!=', 't.currency')
                    ->orWhereColumn('w.minor_unit_scale', '!=', 't.minor_unit_scale'))
                ->count(),
        ];

        return response()->view('billing.rates', compact('tariffs', 'assignments', 'clients', 'tariffOptions', 'filters', 'stats'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Tariffs $tariffs)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'code' => 'required|string|regex:/\A[A-Za-z0-9_-]{1,80}\z/',
            'version' => 'required|integer|min:1|max:1000000',
            'currency' => 'required|string|regex:/\A[A-Z]{3}\z/',
            'minor_unit_scale' => 'required|integer|min:0|max:6',
            'rate_minor_per_minute' => 'required|integer|min:0|max:1000000000',
            'playback_policy' => 'required|in:charge_answered,hold_on_failure',
            'evidence' => 'required|string|min:3|max:1000',
        ]);
        $tariff = $tariffs->create($request->user(), $data, $data['evidence']);

        return redirect()->route('billing.rates')->with('status', 'Rate version '.$tariff->code.' v'.$tariff->version.' created. Tariff versions are immutable.');
    }

    public function assign(Request $request, Tariffs $tariffs)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'tariff_id' => 'required|integer|exists:call_tariffs,id',
            'reason' => 'required|string|min:3|max:1000',
        ]);
        $client = Client::findOrFail($data['client_id']);
        $tariff = CallTariff::findOrFail($data['tariff_id']);
        $tariffs->assign($request->user(), $client, $tariff, $data['reason']);

        return redirect()->route('billing.rates')->with('status', 'Tariff '.$tariff->code.' v'.$tariff->version.' assigned to '.$client->name.'.');
    }
}
