<?php

namespace App\Console\Commands;

use App\Models\CallAttempt;
use App\Services\Billing\Rating;
use App\Services\Clients\Tenancy;
use Illuminate\Console\Command;

class FinalizeAnsweredCalls extends Command
{
    protected $signature = 'billing:finalize-answered {--apply : Deduct answer charges and settle ended calls} {--limit=500}';

    protected $description = 'Recover automatic answered-call billing without an imported provider CDR (preview unless --apply)';

    public function handle(Rating $rating, Tenancy $tenancy): int
    {
        return $tenancy->run(null, function () use ($rating) {
            $calls = CallAttempt::whereNotNull('sent_at')->whereNotNull('answered_at')
                ->where(function ($q) {
                    $q->whereDoesntHave('rating')->orWhereHas('reservation', fn ($r) => $r->where('state', '!=', 'settled'));
                })->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))))->get();
            $this->info('Answered calls requiring billing: '.$calls->count());
            foreach ($calls as $call) {
                $result = $this->option('apply') ? $rating->internal($call) : 'preview';
                $this->line('Attempt '.$call->id.': '.$result);
            }

            return self::SUCCESS;
        });
    }
}
