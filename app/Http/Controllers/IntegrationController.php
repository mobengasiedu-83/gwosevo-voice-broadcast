<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Models\CommercialPlan;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use App\Services\Integrations\Access;
use App\Services\Integrations\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        app(Access::class)->owner($request->user());
        $accountId = $request->user()->client_account_id;
        $subscriptions = DB::table('webhook_subscriptions')->where('client_account_id', $accountId)->where('user_id', $request->user()->id)->orderByDesc('id')->paginate(25, ['id', 'url', 'events', 'active', 'created_at'], 'subscriptions_page');

        return response()->view('integrations.client', ['account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => app(Portal::class)->unread($request->user()),
            'credentials' => DB::table('api_credentials')->where('user_id', $request->user()->id)->where('client_account_id', $accountId)->orderByDesc('id')->paginate(25, ['id', 'name', 'scopes', 'expires_at', 'revoked_at', 'last_used_at'], 'credentials_page'),
            'subscriptions' => $subscriptions,
            'deliveries' => DB::table('webhook_deliveries')->whereIn('webhook_subscription_id', DB::table('webhook_subscriptions')->where('client_account_id', $accountId)->where('user_id', $request->user()->id)->select('id'))->orderByDesc('id')->paginate(25, ['id', 'uuid', 'webhook_subscription_id', 'status', 'attempts', 'http_status', 'failure_reason', 'created_at', 'redacted_at']),
            'limits' => app(Access::class)->limits($accountId),
            'usage' => DB::table('integration_usage')->where('client_account_id', $accountId)->where('bucket', now('UTC')->format('Y-m-d'))->get(['metric', 'used']),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function secret(string $label, string $secret)
    {
        // Never flash credentials to session storage or old input.
        return response()->view('integrations.secret', compact('label', 'secret'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function issue(Request $request, Access $access)
    {
        $d = $request->validate(['name' => 'required|string|max:100', 'scopes' => 'required|array|min:1', 'scopes.*' => ['required', Rule::in(Access::SCOPES)], 'days' => 'required|integer|min:1|max:365']);
        $key = $access->issue($request->user(), $request->user(), $d['name'], $d['scopes'], $d['days']);

        return $this->secret('API credential', $key['token']);
    }

    public function credential(Request $request, int $id, Access $access)
    {
        $access->owner($request->user());
        $request->validate(['action' => 'required|in:rotate,revoke']);
        $key = DB::transaction(function () use ($request, $id, $access) {
            DB::table('client_accounts')->where('id', $request->user()->client_account_id)->lockForUpdate()->firstOrFail();
            $key = DB::table('api_credentials')->where('id', $id)->where('user_id', $request->user()->id)->where('client_account_id', $request->user()->client_account_id)->lockForUpdate()->firstOrFail();
            abort_if($key->revoked_at || now()->gte($key->expires_at), 422, 'Credential is already revoked or expired.');
            DB::table('api_credentials')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($request->user(), 'api.credential_revoked', ClientAccount::findOrFail($key->client_account_id), ['credential_id' => $id]);

            return $request->action === 'rotate' ? $access->issue($request->user(), $request->user(), $key->name, json_decode($key->scopes, true), max(1, (int) ceil(now()->diffInSeconds($key->expires_at) / 86400))) : null;
        });

        return $key ? $this->secret('Replacement API credential', $key['token']) : back()->with('status', 'Credential revoked.');
    }

    public function subscribe(Request $request, Webhooks $webhooks)
    {
        $d = $request->validate(['url' => 'required|string|max:1000', 'events' => 'required|array|min:1', 'events.*' => ['required', Rule::in(Webhooks::EVENTS)]]);
        $subscription = $webhooks->create($request->user(), $d['url'], $d['events']);

        return $this->secret('Webhook signing secret', $subscription['secret']);
    }

    public function subscription(Request $request, int $id)
    {
        app(Access::class)->owner($request->user());
        $request->validate(['action' => 'required|in:disable,rotate']);
        $secret = DB::transaction(function () use ($request, $id) {
            $s = DB::table('webhook_subscriptions')->where('id', $id)->where('user_id', $request->user()->id)->where('client_account_id', $request->user()->client_account_id)->lockForUpdate()->firstOrFail();
            abort_unless($s->active, 422);
            $secret = $request->action === 'rotate' ? Str::random(64) : null;
            DB::table('webhook_subscriptions')->where('id', $id)->update($secret ? ['signing_secret' => Crypt::encryptString($secret), 'updated_at' => now()] : ['active' => false, 'updated_at' => now()]);
            app(Foundation::class)->audit($request->user(), 'webhook.'.$request->action, ClientAccount::findOrFail($s->client_account_id), ['subscription_id' => $id]);

            return $secret;
        });

        return $secret ? $this->secret('Replacement webhook signing secret', $secret) : back()->with('status', 'Webhook disabled.');
    }

    public function retry(Request $request, int $id)
    {
        app(Access::class)->owner($request->user());
        $request->validate(['confirm' => 'accepted']);
        DB::transaction(function () use ($request, $id) {
            $d = DB::table('webhook_deliveries as d')->join('webhook_subscriptions as s', 's.id', '=', 'd.webhook_subscription_id')->where('d.id', $id)->where('s.user_id', $request->user()->id)->where('s.client_account_id', $request->user()->client_account_id)->where('s.active', true)->select('d.*')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($d->status, ['unknown', 'failed'], true) && $d->attempts < 5 && ! $d->redacted_at && now()->subDays(7)->lte($d->created_at), 422);
            DB::table('webhook_deliveries')->where('id', $id)->update(['status' => 'pending', 'next_attempt_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($request->user(), 'webhook.retry_requested', ClientAccount::findOrFail($request->user()->client_account_id), ['delivery_id' => $id]);
        });

        return back()->with('status', 'Retry queued for maintenance.');
    }

    private function staff(Request $request): void
    {
        abort_unless(! $request->user()->isClient() && $request->user()->can('clients.manage'), 403);
    }

    public function plans(Request $request)
    {
        $this->staff($request);

        return view('integrations.plans', ['plans' => DB::table('commercial_plans')->orderBy('id')->get(), 'accounts' => ClientAccount::orderBy('id')->paginate(50), 'usage' => DB::table('integration_usage')->where('bucket', now('UTC')->format('Y-m-d'))->orderBy('client_account_id')->paginate(50, ['*'], 'usage_page')]);
    }

    public function plan(Request $request)
    {
        $this->staff($request);
        $rules = ['name' => 'required|string|max:100'];
        foreach (['requests_per_minute' => 10000, 'requests_per_day' => 1000000, 'import_rows_per_day' => 1000000, 'drafts_per_day' => 10000, 'webhook_limit' => 20] as $field => $max) {
            $rules[$field] = 'required|integer|min:0|max:'.$max;
        }
        $d = $request->validate($rules);
        DB::transaction(function () use ($request, $d) {
            $plan = CommercialPlan::create($d);
            app(Foundation::class)->audit($request->user(), 'integration.plan_created', $plan, ['plan_id' => $plan->id]);
        });

        return back()->with('status', 'Integration plan created. Assign it to an account to apply limits.');
    }

    public function assign(Request $request, int $account)
    {
        $this->staff($request);
        $d = $request->validate(['plan_id' => 'nullable|integer|exists:commercial_plans,id']);
        DB::transaction(function () use ($request, $account, $d) {
            $a = ClientAccount::whereKey($account)->lockForUpdate()->firstOrFail();
            $a->update(['commercial_plan_id' => $d['plan_id'] ?? null]);
            app(Foundation::class)->audit($request->user(), 'integration.plan_assigned', $a, ['plan_id' => $d['plan_id'] ?? null]);
        });

        return back()->with('status', 'Integration limits assigned. Existing subscriptions above a lower limit must be disabled manually.');
    }
}
