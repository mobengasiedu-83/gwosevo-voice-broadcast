<?php

namespace App\Services\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates personal alert records and queues their email/SMS copies.
 *
 * The business event owns the transaction; alert failures are logged rather
 * than rolled back, because a provider or notification-table fault must never
 * reverse a payment, call settlement or review decision.
 */
class Center
{
    public const CATEGORIES = ['review', 'campaign', 'billing', 'payment'];

    /**
     * Create one alert per distinct user and queue the enabled channels.
     *
     * @return array<int, UserNotification>
     */
    public function notifyUsers(iterable $users, array $data): array
    {
        if (! config('notifications.enabled', true)) {
            return [];
        }
        $created = [];
        $seen = [];
        foreach ($users as $user) {
            if (! $user instanceof User || isset($seen[$user->id])) {
                continue;
            }
            $seen[$user->id] = true;
            try {
                $notification = $this->createFor($user, $data);
                if ($notification) {
                    $created[] = $notification;
                }
            } catch (Throwable $e) {
                // Do not include the body, address or provider payload in logs.
                Log::warning('notification.create_failed', [
                    'user_id' => $user->id, 'event' => $data['event_key'] ?? null, 'exception' => $e::class,
                ]);
            }
        }

        return $created;
    }

    public function notifyUser(?User $user, array $data): ?UserNotification
    {
        if (! $user) {
            return null;
        }

        return $this->notifyUsers([$user], $data)[0] ?? null;
    }

    /** All active members of one client account, including owners. */
    public function clientMembers(int $clientId): iterable
    {
        return User::whereHas('clientMembership', function ($query) use ($clientId) {
            $query->where('client_id', $clientId)->where('status', 'active');
        })->get();
    }

    /** Active account owners plus the campaign owner, restricted to this client. */
    public function campaignAudience(int $clientId, ?int $ownerId): iterable
    {
        $roles = User::whereHas('clientMembership', function ($query) use ($clientId) {
            $query->where('client_id', $clientId)->where('status', 'active')->where('role', 'owner');
        })->pluck('id');
        if ($ownerId) {
            $roles->push($ownerId);
        }

        return User::whereIn('id', $roles->unique()->values())
            ->whereHas('clientMembership', fn ($query) => $query->where('client_id', $clientId)->where('status', 'active'))
            ->get();
    }

    /**
     * Staff reviewers who may approve audio: super administrators,
     * administrators and approvers. Client logins never receive these alerts.
     */
    public function audioApprovers(): iterable
    {
        return User::whereDoesntHave('clientMembership')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $user->can('audio.approve'))
            ->values();
    }

    /** The member who uploaded or initiated the event, only while still active. */
    public function activeMember(int $clientId, ?int $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        return User::whereKey($userId)
            ->whereHas('clientMembership', fn ($query) => $query->where('client_id', $clientId)->where('status', 'active'))
            ->first();
    }

    public function summary(User $user): array
    {
        $query = UserNotification::where('user_id', $user->id)->where('in_app', true);
        $items = (clone $query)->latest('id')->limit(8)->get();
        $latestId = (clone $query)->max('id');

        return [
            'unread' => (clone $query)->whereNull('read_at')->count(),
            'latest_id' => (int) $latestId,
            'items' => $items->map(fn (UserNotification $item) => $this->present($item))->all(),
        ];
    }

    public function page(User $user, ?string $category = null, int $perPage = 25)
    {
        $query = UserNotification::where('user_id', $user->id)->where('in_app', true)->with('deliveries');
        if ($category && in_array($category, self::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        return $query->orderByDesc('id')->paginate($perPage, ['*'], 'alerts_page')->through(fn (UserNotification $item) => $this->present($item, true));
    }

    /** Delivery-only records complement the in-app feed without duplicating it. */
    public function deliveryPage(User $user, int $perPage = 25)
    {
        return UserNotification::where('user_id', $user->id)
            ->where('in_app', false)
            ->with('deliveries')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'delivery_page')
            ->through(fn (UserNotification $item) => $this->present($item, true));
    }

    public function markThrough(User $user, int $throughId): bool
    {
        $exists = UserNotification::where('user_id', $user->id)->where('in_app', true)->whereKey($throughId)->exists();
        if (! $exists) {
            return false;
        }
        UserNotification::where('user_id', $user->id)->where('in_app', true)
            ->where('id', '<=', $throughId)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return true;
    }

    public function markAll(User $user): void
    {
        UserNotification::where('user_id', $user->id)->where('in_app', true)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    private function createFor(User $user, array $data): ?UserNotification
    {
        $dedupe = isset($data['dedupe_key']) && $data['dedupe_key'] !== '' ? mb_substr((string) $data['dedupe_key'], 0, 160) : null;
        if ($dedupe) {
            $existing = UserNotification::where('user_id', $user->id)->where('dedupe_key', $dedupe)->first();
            if ($existing) {
                return $existing;
            }
        }

        $attributes = [
            'client_id' => $data['client_id'] ?? null,
            'category' => $data['category'],
            'event_key' => mb_substr((string) $data['event_key'], 0, 80),
            'severity' => $data['severity'] ?? 'info',
            'title' => mb_substr((string) $data['title'], 0, 160),
            'body' => isset($data['body']) ? mb_substr((string) $data['body'], 0, 2000) : null,
            'url' => isset($data['url']) ? mb_substr((string) $data['url'], 0, 2048) : null,
            'data' => $data['data'] ?? null,
            'dedupe_key' => $dedupe,
            'in_app' => (bool) ($data['in_app'] ?? true),
        ];

        try {
            $notification = UserNotification::create($attributes + ['user_id' => $user->id]);
        } catch (QueryException $e) {
            // A concurrent duplicate is expected for low-balance alerts.
            if ($dedupe && $existing = UserNotification::where('user_id', $user->id)->where('dedupe_key', $dedupe)->first()) {
                return $existing;
            }
            throw $e;
        }

        $channels = $data['channels'] ?? ['email', 'sms'];
        foreach (NotificationDelivery::CHANNELS as $channel) {
            if (! in_array($channel, $channels, true) || ! config('notifications.channels.'.$channel, true)) {
                continue;
            }
            $address = $channel === 'email' ? $user->email : $user->phone_e164;
            $delivery = $notification->deliveries()->create([
                'channel' => $channel,
                'status' => $address ? 'queued' : 'skipped',
                'last_error' => $address ? null : ($channel === 'email' ? 'The recipient has no email address.' : 'The recipient has no mobile number.'),
                'queued_at' => $address ? now() : null,
            ]);
            if ($delivery->status !== 'queued') {
                continue;
            }
            try {
                DeliverNotification::dispatch($delivery->id)
                    ->onQueue((string) config('notifications.queue', 'default'))
                    ->afterCommit();
            } catch (Throwable $e) {
                $delivery->forceFill(['status' => 'failed', 'last_error' => 'The alert could not be queued.'])->save();
                Log::warning('notification.queue_failed', ['delivery_id' => $delivery->id, 'channel' => $channel]);
            }
        }

        return $notification;
    }

    private function present(UserNotification $item, bool $withDeliveries = false): array
    {
        $presented = [
            'id' => $item->id,
            'category' => $item->category,
            'event_key' => $item->event_key,
            'severity' => $item->severity,
            'title' => $item->title,
            'body' => $item->body,
            'url' => $item->url,
            'unread' => $item->read_at === null,
            'in_app' => $item->in_app,
            'created_at' => optional($item->created_at)->toIso8601String(),
            'created_label' => optional($item->created_at)->format('j M Y, H:i').' UTC',
        ];
        if ($withDeliveries) {
            $presented['deliveries'] = $item->deliveries->map(fn (NotificationDelivery $delivery) => [
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->last_error,
                'sent_at' => optional($delivery->sent_at)->toIso8601String(),
            ])->values()->all();
        }

        return $presented;
    }
}
