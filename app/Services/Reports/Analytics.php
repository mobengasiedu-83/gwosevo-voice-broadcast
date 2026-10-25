<?php

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Models\User;
use App\Services\Billing\Ledger;
use App\Services\Clients\Portal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Analytics
{
    public function authorize(User $user, string $kind): void
    {
        if ($user->isClient()) {
            app(Portal::class)->authorize($user);
            abort_unless($user->clientCan($kind === 'billing' ? 'billing.read' : 'reports.read') && ($kind === 'billing' || $user->client_role !== 'finance'), 403);
        } else {
            Gate::forUser($user)->authorize($kind === 'billing' ? 'billing.view' : 'reports.view');
        }
    }

    public function filters(User $user, array $input): array
    {
        $zone = $input['timezone'] ?? ($user->isClient() ? ($user->clientAccount?->settings['timezone'] ?? 'Africa/Accra') : 'Africa/Accra');
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            $zone = 'Africa/Accra';
        }
        $defaults = ['kind' => ($user->isClient() ? $user->client_role === 'finance' : ! $user->can('reports.view')) ? 'billing' : 'campaigns', 'timezone' => $zone, 'from' => now($zone)->subDays(29)->format('Y-m-d'), 'to' => now($zone)->format('Y-m-d')];
        $data = Validator::make(array_replace($defaults, $input), [
            'kind' => 'required|in:campaigns,attempts,billing,ivr', 'timezone' => 'required|timezone',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'campaign_id' => 'nullable|integer|min:1', 'status' => 'nullable|regex:/\A[a-z_]{1,40}\z/',
            'client_account_id' => $user->isClient() ? 'prohibited' : 'nullable|integer|min:1',
            'owner_id' => $user->isClient() ? 'prohibited' : 'nullable|integer|min:1',
        ])->validate();
        $this->authorize($user, $data['kind']);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a report period of at most 366 days.']);
        }
        if (! empty($data['campaign_id'])) {
            $this->campaigns($user, $data)->findOrFail($data['campaign_id']);
        }

        return $data;
    }

    public function campaigns(User $user, array $filters = [])
    {
        $q = Campaign::query();
        if ($user->isClient()) {
            $q->where('client_account_id', app(Portal::class)->authorize($user));
        } elseif (! empty($filters['client_account_id'])) {
            $q->where('client_account_id', $filters['client_account_id']);
        }
        if (! empty($filters['owner_id'])) {
            $q->where('owner_id', $filters['owner_id']);
        }
        if (! empty($filters['campaign_id'])) {
            $q->whereKey($filters['campaign_id']);
        }

        return $q;
    }

    private function window($query, string $column, array $filters)
    {
        return $query->where($column, '>=', CarbonImmutable::parse($filters['from'], $filters['timezone'])->startOfDay()->utc())
            ->where($column, '<', CarbonImmutable::parse($filters['to'], $filters['timezone'])->startOfDay()->addDay()->utc());
    }

    public function query(User $user, array $filters)
    {
        $this->authorize($user, $filters['kind']);
        $ids = $this->campaigns($user, $filters)->select('campaigns.id');
        if ($filters['kind'] === 'billing') {
            $q = DB::table('wallet_ledger_entries as l')->join('wallets as w', 'w.id', '=', 'l.wallet_id');
            if ($user->isClient()) {
                $q->where('w.client_account_id', $user->client_account_id);
            } elseif (! empty($filters['client_account_id'])) {
                $q->where('w.client_account_id', $filters['client_account_id']);
            }
            if (! empty($filters['campaign_id']) || ! empty($filters['owner_id'])) {
                $q->whereIn('l.call_attempt_id', DB::table('call_attempts')->whereIn('campaign_recipient_id', DB::table('campaign_recipients')->whereIn('campaign_id', $ids)->select('id'))->select('id'));
            }
            $q = $this->window($q, 'l.created_at', $filters);
            if (! empty($filters['status'])) {
                $q->where('l.kind', $filters['status']);
            }

            return $q->select('l.id', 'l.created_at', 'l.kind', 'l.call_attempt_id', 'w.client_account_id', 'w.currency', 'w.minor_digits', 'l.amount_minor', 'l.balance_after_minor');
        }
        if ($filters['kind'] === 'campaigns') {
            $q = DB::table('campaigns as c')->whereIn('c.id', $ids);
            $q = $this->window($q, 'c.created_at', $filters);
            if (! empty($filters['status'])) {
                $q->where('c.status', $filters['status']);
            }
            $q->select('c.id', 'c.name', 'c.status', 'c.timezone', 'c.created_at');
            $q->selectSub(DB::table('campaign_recipients as r')->whereColumn('r.campaign_id', 'c.id')->selectRaw('count(*)'), 'audience');
            $q->selectSub(DB::table('campaign_recipients as r')->whereColumn('r.campaign_id', 'c.id')->where('r.status', 'excluded')->selectRaw('count(*)'), 'excluded');
            foreach (['answered_at' => 'answered_recipients', 'playback_completed_at' => 'delivered_recipients'] as $field => $alias) {
                $q->selectSub(DB::table('campaign_recipients as r')->whereColumn('r.campaign_id', 'c.id')->whereExists(fn ($a) => $a->selectRaw('1')->from('call_attempts as a')->whereColumn('a.campaign_recipient_id', 'r.id')->whereNotNull('a.'.$field))->selectRaw('count(*)'), $alias);
            }
            $q->selectSub(DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->whereColumn('r.campaign_id', 'c.id')->selectRaw('count(*)'), 'attempts');

            return $q;
        }
        $q = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->whereIn('c.id', $ids);
        if ($filters['kind'] === 'ivr') {
            $q->join('dtmf_responses as d', 'd.call_attempt_id', '=', 'a.id');
            $q = $this->window($q, 'd.occurred_at', $filters);
            if (! empty($filters['status'])) {
                $q->where('a.outcome', $filters['status']);
            }

            return $q->select('d.id', 'c.name as campaign', 'a.id as attempt_id', 'd.node_id', 'd.digit', 'd.response_type', 'd.occurred_at');
        }
        $q = $this->window($q, 'a.created_at', $filters);
        if (! empty($filters['status'])) {
            $q->where(fn ($f) => $f->where('a.outcome', $filters['status'])->orWhere('a.state', $filters['status']));
        }

        return $q->select('a.id', 'c.name as campaign', 'r.phone_e164 as recipient_phone', 'a.attempt_no', 'a.state', 'a.outcome', 'a.created_at', 'a.sent_at', 'a.answered_at', 'a.playback_started_at', 'a.playback_completed_at', 'a.playback_failed_at', 'a.ended_at', 'a.billable_seconds', 'a.transfer_answered_at', 'a.opted_out_at');
    }

    public function headers(string $kind): array
    {
        return match ($kind) {
            'campaigns' => ['Campaign ID', 'Campaign', 'Status', 'Created UTC', 'Campaign timezone', 'Audience', 'DND/other exclusions', 'Attempts', 'Recipients answered', 'Recipients playback completed'],
            'attempts' => ['Attempt ID', 'Campaign', 'Recipient phone', 'Try', 'State', 'Outcome', 'Created UTC', 'Sent UTC', 'Answered UTC', 'Playback start UTC', 'Playback complete UTC', 'Playback failed UTC', 'Ended UTC', 'Verified seconds', 'Transfer answered UTC', 'Opt-out UTC'],
            'billing' => ['Ledger ID', 'Posted UTC', 'Type', 'Attempt ID', 'Client account ID', 'Currency', 'Amount', 'Balance after'],
            'ivr' => ['Response ID', 'Campaign', 'Attempt ID', 'Node', 'Digit', 'Response type', 'Occurred UTC'],
        };
    }

    public function row(string $kind, object $r): array
    {
        return match ($kind) {
            'campaigns' => [$r->id, $r->name, $r->status, $r->created_at, $r->timezone, $r->audience, $r->excluded, $r->attempts, $r->answered_recipients, $r->delivered_recipients],
            'attempts' => [$r->id, $r->campaign, $r->recipient_phone, $r->attempt_no, $r->state, $r->outcome, $r->created_at, $r->sent_at, $r->answered_at, $r->playback_started_at, $r->playback_completed_at, $r->playback_failed_at, $r->ended_at, $r->billable_seconds, $r->transfer_answered_at, $r->opted_out_at],
            'billing' => [$r->id, $r->created_at, $r->kind, $r->call_attempt_id, $r->client_account_id, $r->currency, Ledger::format($r->amount_minor, $r->minor_digits), Ledger::format($r->balance_after_minor, $r->minor_digits)],
            'ivr' => [$r->id, $r->campaign, $r->attempt_id, $r->node_id, $r->digit, $r->response_type, $r->occurred_at],
        };
    }

    public function summary(User $user, array $filters): array
    {
        $base = $this->query($user, $filters);
        $q = DB::query()->fromSub($base, 'report_rows');
        if ($filters['kind'] === 'campaigns') {
            $stats = (clone $q)->selectRaw('count(*) as campaigns, coalesce(sum(audience),0) as audience, coalesce(sum(excluded),0) as excluded, coalesce(sum(attempts),0) as attempts, coalesce(sum(answered_recipients),0) as answered, coalesce(sum(delivered_recipients),0) as playback')->first();

            return ['metrics' => (array) $stats, 'groups' => (clone $q)->selectRaw('status as label, count(*) as total')->groupBy('status')->get()];
        }
        if ($filters['kind'] === 'attempts') {
            $stats = (clone $q)->selectRaw('count(*) as attempts, count(sent_at) as sent, count(answered_at) as answered, sum(case when sent_at is not null and answered_at is not null then 1 else 0 end) as answered_sent, sum(case when answered_at is not null and playback_completed_at is not null then 1 else 0 end) as playback_answered, count(playback_started_at) as playback_started, count(playback_completed_at) as playback_completed, count(playback_failed_at) as playback_failed, count(transfer_answered_at) as transfer_answered, count(opted_out_at) as opted_out')->first();

            return ['metrics' => (array) $stats, 'groups' => (clone $q)->selectRaw('coalesce(outcome, state) as label, count(*) as total')->groupByRaw('coalesce(outcome, state)')->get()];
        }
        if ($filters['kind'] === 'billing') {
            return ['metrics' => ['entries' => (clone $q)->count()], 'groups' => (clone $q)->selectRaw('currency, minor_digits, kind as label, count(*) as total, sum(amount_minor) as amount_minor')->groupBy('currency', 'minor_digits', 'kind')->get()];
        }

        return ['metrics' => ['responses' => (clone $q)->count(), 'responding_attempts' => (clone $q)->distinct()->count('attempt_id'), 'valid_digits' => (clone $q)->where('response_type', 'digit')->count(), 'invalid_inputs' => (clone $q)->where('response_type', 'invalid')->count(), 'timeouts' => (clone $q)->where('response_type', 'timeout')->count()], 'groups' => (clone $q)->selectRaw('node_id, coalesce(digit, response_type) as label, count(*) as total')->groupBy('node_id', 'digit', 'response_type')->get()];
    }
}
