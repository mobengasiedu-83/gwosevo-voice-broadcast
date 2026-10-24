<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Campaigns\Recurring;
use App\Services\Clients\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CampaignSeriesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isClient()) {
            app(Portal::class)->authorize($user);
        } else {
            Gate::authorize('campaigns.manage');
        }
        $q = DB::table('campaign_series')->when(! $user->isSuperAdministrator(), fn ($q) => $q->where('user_id', $user->id));
        if ($user->isClient()) {
            $q->where('client_account_id', $user->client_account_id);
        }
        $series = $q->orderByDesc('id')->paginate(20);

        return view('campaigns.series', [
            'series' => $series,
            'campaigns' => Campaign::where('owner_id', $user->id)->whereNull('archived_at')->latest()->limit(200)->get(),
            'occurrences' => DB::table('campaign_occurrences')->whereIn('campaign_series_id', $series->pluck('id'))->orderByDesc('id')->limit(100)->get()->groupBy('campaign_series_id'),
            'account' => $user->isClient() ? $user->clientAccount : null,
            'unread' => $user->isClient() ? app(Portal::class)->unread($user) : 0,
        ]);
    }

    public function store(Request $request, Recurring $recurring)
    {
        $data = $request->validate(['campaign_id' => 'required|integer']);
        $recurring->create($request->user(), Campaign::findOrFail($data['campaign_id']), $request->all());

        return back()->with('status', 'Recurring schedule saved. Existing dispatch settings remain unchanged.');
    }

    public function stop(Request $request, int $series, Recurring $recurring)
    {
        $recurring->stop($request->user(), $series);

        return back()->with('status', 'Future occurrences stopped. Existing campaigns retain their own controls.');
    }
}
