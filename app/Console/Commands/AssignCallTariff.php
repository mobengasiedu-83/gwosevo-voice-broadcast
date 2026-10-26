<?php

namespace App\Console\Commands;

use App\Models\CallTariff;
use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Tariffs;
use Illuminate\Console\Command;

class AssignCallTariff extends Command
{
    protected $signature = 'billing:assign {client} {tariff} {--actor=} {--reason=}';

    protected $description = 'Assign a tariff to future client attempts without changing historical snapshots';

    public function handle(Tariffs $tariffs): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        try {
            $tariffs->assign($actor, Client::findOrFail($this->argument('client')), CallTariff::findOrFail($this->argument('tariff')), (string) $this->option('reason'));
        } catch (\Throwable $e) {
            $this->error('Assignment rejected. Check account, tariff and reason.');

            return self::FAILURE;
        }
        $this->info('Tariff assigned to future attempts.');

        return self::SUCCESS;
    }
}
