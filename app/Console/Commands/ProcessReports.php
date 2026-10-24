<?php

namespace App\Console\Commands;

use App\Services\Reports\Exports;
use Illuminate\Console\Command;

class ProcessReports extends Command
{
    protected $signature = 'reports:maintain';

    protected $description = 'Queue due report schedules and prune expired private report artifacts';

    public function handle(Exports $exports): int
    {
        $exports->maintain();
        $this->info('Report schedules and retention processed.');

        return self::SUCCESS;
    }
}
