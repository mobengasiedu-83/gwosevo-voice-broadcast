<?php

namespace App\Models\Concerns;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Services\Clients\AccountContext;

trait ClientChild
{
    public static function bootClientChild(): void
    {
        static::addGlobalScope('client_account', function ($query) {
            if (app(AccountContext::class)->id() !== null) {
                [$parent, $key] = match ($query->getModel()->getTable()) {
                    'campaign_versions', 'campaign_runs', 'campaign_recipients' => [Campaign::class, 'campaign_id'],
                    'call_attempts' => [CampaignRecipient::class, 'campaign_recipient_id'],
                    'call_events', 'dtmf_responses' => [CallAttempt::class, 'call_attempt_id'],
                    'contact_consents' => [Contact::class, 'contact_id'],
                };
                $query->whereIn($query->getModel()->qualifyColumn($key), $parent::query()->select('id'));
            }
        });
    }
}
