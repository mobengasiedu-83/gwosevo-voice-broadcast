<?php

namespace App\Http\Controllers;

use App\Services\Clients\Portal;
use Illuminate\Http\Request;

class ClientPortalController extends Controller
{
    private function shared(Request $request, Portal $portal): array
    {
        $portal->authorize($request->user());

        return ['account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => $portal->unread($request->user())];
    }

    public function dashboard(Request $request, Portal $portal)
    {
        return view('clients.dashboard', $this->shared($request, $portal) + [
            'summary' => $portal->summary($request->user()),
            'campaigns' => $portal->campaigns($request->user())->latest()->limit(5)->get(['id', 'name', 'status', 'scheduled_at', 'timezone', 'created_at']),
            'notices' => $portal->notices($request->user())->orderByDesc('occurred_at')->orderByDesc('id')->limit(4)->get(),
        ]);
    }

    public function campaigns(Request $request, Portal $portal)
    {
        $data = $request->validate(['status' => 'nullable|in:draft,ready,scheduled,running,paused,completed,cancelled', 'q' => 'nullable|string|max:100']);
        $query = $portal->campaigns($request->user());
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }

        return view('clients.campaigns', $this->shared($request, $portal) + ['campaigns' => $query->latest()->paginate(15, ['id', 'name', 'status', 'scheduled_at', 'timezone', 'created_at'])->withQueryString()]);
    }

    public function notifications(Request $request, Portal $portal)
    {
        return view('clients.notifications', $this->shared($request, $portal) + ['notices' => $portal->notices($request->user())->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20)]);
    }

    public function read(Request $request, Portal $portal, int $notice)
    {
        $portal->markRead($request->user(), $notice);

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request, Portal $portal)
    {
        $portal->markAllRead($request->user());

        return back()->with('status', 'Notifications marked as read.');
    }
}
