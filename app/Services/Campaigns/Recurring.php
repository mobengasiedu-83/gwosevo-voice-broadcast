<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Campaigns;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Recurring
{
    public function authorize(User $user, Campaign $campaign): void
    {
        if ($user->isClient()) {
            app(Campaigns::class)->access($user, $campaign, true);
        } else {
            Gate::forUser($user)->authorize('campaigns.manage');
            app(Workspace::class)->own($user, $campaign);
        }
    }

    public function create(User $user, Campaign $campaign, array $input): int
    {
        $this->authorize($user, $campaign);
        $data = Validator::make($input, [
            'frequency' => 'required|in:daily,weekly,monthly', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'local_time' => 'required|date_format:H:i',
            'auto_start' => 'nullable|boolean',
        ])->validate();

        return DB::transaction(function () use ($user, $campaign, $data) {
            app(Engine::class)->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $campaign);
            abort_if($campaign->archived_at || ! empty($campaign->configuration['client_test']), 422, 'Use an unarchived production campaign as the template.');
            $start = CarbonImmutable::parse($data['starts_on'].' '.$data['local_time'], $campaign->timezone);
            if (! $start->isFuture() || $start->diffInDays(CarbonImmutable::parse($data['ends_on'], $campaign->timezone)->endOfDay(), false) > 366) {
                throw ValidationException::withMessages(['starts_on' => 'Choose a future start and an end within one year.']);
            }
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (DB::table('campaign_series')->where('user_id', $user->id)->where('active', true)->count() >= 10) {
                throw ValidationException::withMessages(['frequency' => 'At most ten active recurring schedules per user.']);
            }
            // Validate current media, audience, IVR and connection before accepting a template.
            app(Readiness::class)->snapshot($campaign);
            if (! empty($data['auto_start']) && ! $user->isClient()) {
                Gate::forUser($user)->authorize('broadcast.operate');
            }
            $template = $campaign->only(['name', 'purpose', 'type', 'timezone', 'configuration']);
            $id = DB::table('campaign_series')->insertGetId([
                'campaign_id' => $campaign->id, 'user_id' => $user->id, 'client_account_id' => $campaign->client_account_id,
                'template' => json_encode($template, JSON_THROW_ON_ERROR), 'frequency' => $data['frequency'],
                'timezone' => $campaign->timezone, 'anchor_date' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'local_time' => $data['local_time'], 'next_run_at' => $start->utc(), 'auto_start' => ! empty($data['auto_start']),
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(Foundation::class)->audit($user, 'campaign.series_created', $campaign, ['series_id' => $id, 'auto_start' => ! empty($data['auto_start'])]);

            return $id;
        });
    }

    public function stop(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            app(Engine::class)->mutex();
            $s = DB::table('campaign_series')->where('id', $id)->lockForUpdate()->first();
            abort_unless($s && ((int) $s->user_id === $user->id || $user->isSuperAdministrator()), 404);
            $campaign = Campaign::findOrFail($s->campaign_id);
            $this->authorize($user, $campaign);
            DB::table('campaign_series')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            app(Foundation::class)->audit($user, 'campaign.series_stopped', $campaign, ['series_id' => $id]);
        });
    }

    public function next(object $s, CarbonImmutable $current): CarbonImmutable
    {
        $date = $current->timezone($s->timezone);
        $date = match ($s->frequency) {
            'daily' => $date->addDay(), 'weekly' => $date->addWeek(),
            'monthly' => $date->startOfMonth()->addMonth()->day(min((int) substr($s->anchor_date, 8, 2), $date->startOfMonth()->addMonth()->daysInMonth)),
        };

        // Reconstruct local wall-clock time across DST changes.
        return CarbonImmutable::parse($date->format('Y-m-d').' '.$s->local_time, $s->timezone)->utc();
    }

    public function process(): void
    {
        DB::table('campaign_series')->where('active', true)->where('next_run_at', '<=', now())->orderBy('id')->limit(50)->get()->each(function ($candidate) {
            DB::transaction(function () use ($candidate) {
                app(Engine::class)->mutex();
                $s = DB::table('campaign_series')->where('id', $candidate->id)->lockForUpdate()->first();
                if (! $s || ! $s->active || now()->lt($s->next_run_at)) {
                    return;
                }
                $slot = CarbonImmutable::parse($s->next_run_at, 'UTC');
                $next = $this->next($s, $slot);
                while ($next->lte(now())) {
                    $next = $this->next($s, $next);
                }
                DB::table('campaign_series')->where('id', $s->id)->update(['next_run_at' => $next, 'active' => $next->timezone($s->timezone)->format('Y-m-d') <= $s->ends_on, 'updated_at' => now()]);
                if (DB::table('campaign_occurrences')->where('campaign_series_id', $s->id)->where('scheduled_for', $s->next_run_at)->exists()) {
                    return;
                }
                $status = 'skipped';
                $reason = null;
                $child = null;
                try {
                    $user = User::findOrFail($s->user_id);
                    $source = Campaign::findOrFail($s->campaign_id);
                    $this->authorize($user, $source);
                    $template = json_decode($s->template, true, 64, JSON_THROW_ON_ERROR);
                    $config = $template['configuration'];
                    if ($source->archived_at || $source->stop_requested || $source->status === 'cancelled') {
                        $reason = 'Template stopped or archived.';
                        DB::table('campaign_series')->where('id', $s->id)->update(['active' => false]);
                    } elseif ($slot->timezone($s->timezone)->format('Y-m-d') > $s->ends_on || $slot->lt(now()->subMinutes(15))) {
                        $reason = 'Expired or missed slot; no backfill calls.';
                    } elseif (in_array($slot->timezone($s->timezone)->format('Y-m-d'), $config['excluded_dates'] ?? [], true) || (! empty($config['expires_on']) && $slot->timezone($s->timezone)->format('Y-m-d') > $config['expires_on'])) {
                        $reason = 'Excluded date or campaign expiry.';
                    } elseif (in_array($source->status, ['running', 'paused', 'scheduled'], true) || DB::table('campaign_occurrences')->join('campaigns', 'campaigns.id', '=', 'campaign_occurrences.campaign_id')->where('campaign_series_id', $s->id)->whereIn('campaigns.status', ['draft', 'ready', 'running', 'paused', 'scheduled'])->exists()) {
                        $reason = 'Earlier occurrence still needs review or is active.';
                    } else {
                        // A nested transaction rolls back failed launch side effects before recording the blocked slot.
                        $child = DB::transaction(function () use ($s, $template, $config, $user, $slot) {
                            unset($config['scheduled_at']);
                            $campaign = Campaign::create($template + ['owner_id' => $s->user_id, 'client_account_id' => $s->client_account_id, 'source_campaign_id' => $s->campaign_id, 'status' => 'ready']);
                            $campaign->update(['configuration' => $config]);
                            app(Readiness::class)->snapshot($campaign);
                            if ($s->auto_start) {
                                app(Engine::class)->control($user, $campaign, 'start');
                            }
                            app(Foundation::class)->audit($user, 'campaign.occurrence_created', $campaign, ['series_id' => $s->id, 'scheduled_for' => $slot->toIso8601String()]);

                            return $campaign;
                        });
                        $status = $s->auto_start ? 'started' : 'ready';
                    }
                } catch (\Throwable $e) {
                    $status = 'blocked';
                    $reason = 'Access or launch validation failed. Review account, permissions, media, billing and dispatch readiness.';
                    DB::table('campaign_series')->where('id', $s->id)->update(['active' => false]);
                }
                DB::table('campaign_occurrences')->insert(['campaign_series_id' => $s->id, 'scheduled_for' => $s->next_run_at, 'campaign_id' => $child?->id, 'status' => $status, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            });
        });
    }
}
