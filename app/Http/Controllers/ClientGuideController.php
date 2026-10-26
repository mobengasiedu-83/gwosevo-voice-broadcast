<?php

namespace App\Http\Controllers;

use App\Services\Clients\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientGuideController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy)
    {
        Gate::authorize('dashboard.view');
        abort_unless($tenancy->membership($request->user()) && $tenancy->usable($request->user()), 403);

        return response()->view('client-dashboard.guide')->header('Cache-Control', 'private, no-store');
    }
}
