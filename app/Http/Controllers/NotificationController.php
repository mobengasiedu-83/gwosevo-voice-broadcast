<?php

namespace App\Http\Controllers;

use App\Services\Notifications\Center;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, Center $center)
    {
        $filter = $request->validate(['category' => 'nullable|in:'.implode(',', Center::CATEGORIES)]);
        $user = $request->user();

        return response()->view('notifications.index', [
            'alerts' => $center->page($user, $filter['category'] ?? null),
            'summary' => $center->summary($user),
            'deliveries' => $center->deliveryPage($user),
            'category' => $filter['category'] ?? null,
            'tab' => 'overview',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, Center $center)
    {
        $data = $request->validate(['through_id' => 'required|integer|min:1']);
        abort_unless($center->markThrough($request->user(), (int) $data['through_id']), 404);

        return back()->with('status', 'Account alerts marked as read.');
    }
}
