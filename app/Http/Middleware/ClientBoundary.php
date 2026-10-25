<?php

namespace App\Http\Middleware;

use App\Services\Clients\Capabilities;
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
            abort_unless($request->routeIs('legal.policies', 'client.team', 'client.team.*', 'client.organization', 'client.organization.*', 'client.integrations', 'client.integrations.*', 'client.reports', 'client.reports.*', 'client.notifications.*', 'client.wallet', 'client.audio', 'client.audio.*', 'client.contacts', 'client.contacts.*', 'client.groups', 'client.groups.*', 'client.imports', 'client.imports.*', 'client.foundation', 'client.campaigns', 'client.campaigns.*', 'client.notifications', 'client.notifications.read', 'client.notifications.read-all', 'client.settings', 'client.settings.*', 'logout'), 403, 'This internal workspace is not available to client accounts.');
        }

        if ($user?->isClient() && ($ability = Capabilities::route($request))) {
            abort_unless($user->clientCan($ability), 403, 'Your client role does not permit this action.');
        }
        $response = $next($request);
        if ($user?->isClient()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
