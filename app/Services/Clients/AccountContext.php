<?php

namespace App\Services\Clients;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class AccountContext
{
    private ?int $accountId = null;

    public function id(): ?int
    {
        return $this->accountId ?? (auth()->user()?->isClient() ? (int) auth()->user()->client_account_id : null);
    }

    public function active(int $id): bool
    {
        return DB::table('client_accounts')->where('id', $id)->where('status', 'active')->exists();
    }

    public function run(int $accountId, Closure $work): mixed
    {
        abort_unless($this->active($accountId), 403, 'Client account is suspended.');
        $previous = $this->accountId;
        $this->accountId = $accountId;
        try {
            return $work();
        } finally {
            $this->accountId = $previous;
        }
    }

    public function sameAccount($left, $right): void
    {
        abort_unless($left->client_account_id === $right->client_account_id, 403, 'Records must belong to the same client account.');
    }

    public function assertOwned(User $actor, $record): void
    {
        if ($actor->isClient()) {
            abort_unless($actor->clientAccountActive() && (int) $record->client_account_id === (int) $actor->client_account_id, 403);
        }
    }
}
