<?php

namespace App\Services\Dashboard;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Scope
{
    public function campaigns(User $user)
    {
        return Campaign::query()->when(! $user->can('dashboard.global'), fn ($q) => $q->where('owner_id', $user->id));
    }

    public function attempts(User $user)
    {
        return DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->whereIn('c.id', $this->campaigns($user)->select('id'))->whereNotNull('a.uuid');
    }
}
