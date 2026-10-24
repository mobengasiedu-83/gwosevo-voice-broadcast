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
            abort_unless($request->routeIs('client.wallet', 'client.audio', 'client.audio.*', 'client.contacts', 'client.contacts.*', 'client.groups', 'client.groups.*', 'client.imports', 'client.imports.*', 'client.foundation', 'client.campaigns', 'client.campaigns.*', 'client.notifications', 'client.notifications.read', 'client.notifications.read-all', 'client.settings', 'client.settings.update', 'logout'), 403, 'This internal workspace is not available to client accounts.');
        }

        $response = $next($request);
        if ($user?->isClient()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
