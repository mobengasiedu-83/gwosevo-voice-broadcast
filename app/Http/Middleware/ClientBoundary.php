<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientBoundary
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->isClient()) {
            if (! $user->clientAccountActive()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(403, 'Client account is suspended or unavailable.');
            }
            abort_unless($request->routeIs('client.foundation', 'logout'), 403, 'This internal workspace is not available to client accounts.');
        }

        return $next($request);
    }
}
