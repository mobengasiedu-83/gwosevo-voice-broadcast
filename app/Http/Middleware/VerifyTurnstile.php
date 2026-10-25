<?php

namespace App\Http\Middleware;

use App\Services\Identity\Turnstile;
use Closure;
use Illuminate\Http\Request;

class VerifyTurnstile
{
    public function handle(Request $request, Closure $next, string $action)
    {
        app(Turnstile::class)->verify($request, $action);

        return $next($request);
    }
}
