<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CallRatingController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('telephony.manage');
        $filters = $request->validate(['resolved' => 'nullable|in:1', 'issue' => 'nullable|string|max:64']);
        $issues = DB::table('billing_exceptions as b')->join('call_attempts as a', 'a.id', '=', 'b.call_attempt_id')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->when(empty($filters['resolved']), fn ($q) => $q->whereNull('b.resolved_at'), fn ($q) => $q->whereNotNull('b.resolved_at'))
            ->when($filters['issue'] ?? null, fn ($q, $issue) => $q->where('b.issue', $issue))->orderByDesc('b.id')
            ->paginate(50, ['b.id', 'b.issue', 'b.created_at', 'b.resolved_at', 'a.uuid', 'a.id as attempt_id', 'c.name as campaign_name'])->withQueryString();

        return response()->view('billing.exceptions', compact('issues', 'filters'))->header('Cache-Control', 'private, no-store');
    }
}
