<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\Tariffs;
use Illuminate\Console\Command;

class CreateCallTariff extends Command
{
    protected $signature = 'billing:tariff {file : Approved tariff JSON object} {--actor=} {--evidence=}';

    protected $description = 'Create an immutable approved tariff version using exact integer minor units';

    public function handle(Tariffs $tariffs): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        $file = $this->argument('file');
        if (! is_file($file) || filesize($file) > 10000) {
            $this->error('Provide a bounded tariff JSON object.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $tariff = $tariffs->create($actor, $data, (string) $this->option('evidence'));
        } catch (\Throwable $e) {
            $this->error('Tariff rejected. Check fields, approval reference and unique code/version.');

            return self::FAILURE;
        }
        $this->info('Immutable tariff created: '.$tariff->id);

        return self::SUCCESS;
    }
}
