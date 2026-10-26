<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Clients\Notices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientNoticeController extends Controller
{
    public function index(Request $request, Notices $notices)
    {
        Gate::authorize('dashboard.view');

        return response()->view('client-dashboard.notifications', ['notices' => $notices->page($request->user()), 'summary' => $notices->summary($request->user())])->header('Cache-Control', 'private, no-store');
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
}
