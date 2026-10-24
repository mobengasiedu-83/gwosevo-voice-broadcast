<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Clients\AccountContext;
use App\Services\Integrations\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiCredential
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        abort_unless(config('integrations.api_enabled'), 503, 'Client API is disabled.');
        abort_if((int) $request->server('CONTENT_LENGTH') > 1048576 || strlen($request->getContent()) > 1048576, 413);
        $token = $request->bearerToken() ?? '';
        abort_unless(preg_match('/\Avc_([a-f0-9-]{36})\.([A-Za-z0-9]{64})\z/', $token, $m), 401, 'Invalid API credential.');
        $key = DB::table('api_credentials')->where('uuid', $m[1])->first();
        abort_unless($key && ! $key->revoked_at && now()->lt($key->expires_at) && hash_equals($key->token_hash, hash('sha256', $m[2])), 401, 'Invalid API credential.');
        $owner = User::findOrFail($key->user_id);
        abort_unless($owner->client_account_id === (int) $key->client_account_id, 403);
        app(Access::class)->owner($owner);
        $scope = $request->route()->defaults['scope'] ?? '';
        abort_unless(in_array($scope, json_decode($key->scopes, true), true), 403, 'Required API scope is missing.');
        app(Access::class)->consume($key->client_account_id, 'requests_per_minute');
        app(Access::class)->consume($key->client_account_id, 'requests_per_day');
        $request->attributes->set('api_credential', $key);
        $request->setUserResolver(fn () => $owner);
        $previous = Auth::user(); Auth::setUser($owner);
        $requestId = (string) Str::uuid();
        try {
            $response = app(AccountContext::class)->run($key->client_account_id, fn () => $next($request));
            DB::table('api_credentials')->where('id', $key->id)->update(['last_used_at' => now()]);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Request-ID', $requestId);
            Log::channel('api')->info('API request completed.', ['request_id' => $requestId, 'credential_id' => $key->id, 'route' => $request->route()->getName(), 'status' => $response->getStatusCode()]);
            return $response;
        } finally {
            if ($previous) Auth::setUser($previous); else Auth::forgetGuards();
        }
    }
}
