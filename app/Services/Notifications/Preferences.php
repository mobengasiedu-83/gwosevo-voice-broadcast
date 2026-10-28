<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;

/**
 * Per-login opt-ins for the optional email and SMS copies of account events.
 *
 * Every subscription starts switched off: a client only receives email or SMS
 * after they enable it in Account → Notifications. The personal in-app alert is
 * always recorded so the event stays visible in the account inbox.
 */
class Preferences
{
    /** Optional event subscriptions and the channels each may use. */
    public const SUBSCRIPTIONS = [
        'campaign_completed' => [
            'label' => 'Campaign finished',
            'hint' => 'When a campaign has called every eligible recipient.',
            'channels' => ['email', 'sms'],
        ],
    ];

    public const CHANNEL_LABELS = ['email' => 'Email copy', 'sms' => 'SMS copy'];

    /**
     * Toggles to render for one login, with their current state.
     *
     * @return list<array{key:string,subscription:string,channel:string,label:string,hint:string,enabled:bool}>
     */
    public function options(User $user): array
    {
        $options = [];
        foreach (self::SUBSCRIPTIONS as $subscription => $definition) {
            foreach ($definition['channels'] as $channel) {
                $options[] = [
                    'key' => $this->key($subscription, $channel),
                    'subscription' => $subscription,
                    'channel' => $channel,
                    'label' => $definition['label'].' — '.self::CHANNEL_LABELS[$channel],
                    'hint' => $definition['hint'],
                    'enabled' => $this->enabled($user, $subscription, $channel),
                ];
            }
        }

        return $options;
    }

    public function key(string $subscription, string $channel): string
    {
        return $subscription.'_'.$channel;
    }

    public function enabled(User $user, string $subscription, string $channel): bool
    {
        // Anything other than an explicit true keeps the channel off.
        return ($user->portal_preferences[$this->key($subscription, $channel)] ?? false) === true;
    }

    /**
     * Channels this login has opted into for one subscription, in delivery order.
     *
     * @return list<string>
     */
    public function channels(User $user, string $subscription): array
    {
        $channels = self::SUBSCRIPTIONS[$subscription]['channels'] ?? [];

        return array_values(array_filter(
            $channels,
            fn (string $channel) => $this->enabled($user, $subscription, $channel)
        ));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [];
        foreach (self::SUBSCRIPTIONS as $subscription => $definition) {
            foreach ($definition['channels'] as $channel) {
                $rules[$this->key($subscription, $channel)] = ['nullable', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * Store the posted toggles on the member's own login only. Unknown keys are
     * ignored and an absent toggle falls back to the disabled default.
     */
    public function update(User $user, array $input): void
    {
        $values = [];
        foreach (array_keys($this->rules()) as $key) {
            $values[$key] = (bool) ($input[$key] ?? false);
        }
        DB::transaction(function () use ($user, $values) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $fresh->forceFill(['portal_preferences' => array_merge($fresh->portal_preferences ?? [], $values)])->save();
            app(Foundation::class)->audit($fresh, 'identity.preferences_updated', $fresh);
        });
    }
}
