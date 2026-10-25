<?php

namespace App\Http\Middleware;

use App\Services\Clients\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveClient
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && (int) $request->session()->get('auth_version', 0) !== (int) $request->user()->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Please sign in again.');
        }
        if (! $request->routeIs('logout')) {
            abort_unless(app(Tenancy::class)->usable($request->user()), 403, 'Client account or membership is inactive.');
        }

        return $next($request);
    }
}
