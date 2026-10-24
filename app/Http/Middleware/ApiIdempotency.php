<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET')) return $next($request);
        $key = $request->header('Idempotency-Key', '');
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{8,100}\z/', $key), 422, 'An Idempotency-Key of 8–100 letters, digits, underscores or hyphens is required.');
        $credential = $request->attributes->get('api_credential');
        $fingerprint = hash('sha256', $request->method().'|'.$request->getPathInfo().'|'.$request->getContent());
        return DB::transaction(function () use ($request, $next, $credential, $key, $fingerprint) {
            DB::table('api_credentials')->where('id', $credential->id)->lockForUpdate()->firstOrFail();
            $lookup = ['api_credential_id' => $credential->id, 'key_hash' => hash('sha256', $key)];
            $row = DB::table('api_idempotency')->where($lookup)->first();
            if ($row) {
                abort_unless(hash_equals($row->request_hash, $fingerprint), 409, 'Idempotency key was used for a different request.');
                abort_if(now()->gte($row->expires_at), 409, 'Idempotency key expired; use a new key.');
                return response($row->response_body, $row->response_status)->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }
            $response = $next($request);
            if ($response->getStatusCode() < 400) DB::table('api_idempotency')->insert($lookup + ['request_hash' => $fingerprint, 'response_status' => $response->getStatusCode(), 'response_body' => $response->getContent(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
            return $response;
        });
    }
}
