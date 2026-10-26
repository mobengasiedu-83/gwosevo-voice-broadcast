<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Clients\Notices;
use App\Services\Notifications\Center;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientNoticeController extends Controller
{
    public function index(Request $request, Notices $notices, Center $center)
    {
        Gate::authorize('dashboard.view');
        $filter = $request->validate(['category' => 'nullable|in:'.implode(',', Center::CATEGORIES)]);
        $user = $request->user();

        return response()->view('client-dashboard.notifications', [
            'notices' => $notices->page($user),
            'summary' => $notices->summary($user),
            'alerts' => $center->page($user, $filter['category'] ?? null),
            'alertSummary' => $center->summary($user),
            'deliveries' => $center->deliveryPage($user),
            'category' => $filter['category'] ?? null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, Notices $notices)
    {
        Gate::authorize('dashboard.view');
        $data = $request->validate(['through_id' => 'required|integer|min:1']);
        abort_unless($notices->visibleThrough($request->user(), (int) $data['through_id']), 404);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['notice_read_through_id' => max($user->notice_read_through_id, (int) $data['through_id'])])->save();
        });

        return back()->with('status', 'Notices marked as read.');
    }

    public function readAlerts(Request $request, Center $center)
    {
        Gate::authorize('dashboard.view');
        $data = $request->validate(['through_id' => 'required|integer|min:1']);
        abort_unless($center->markThrough($request->user(), (int) $data['through_id']), 404);

        return back()->with('status', 'Account alerts marked as read.');
    }
}
