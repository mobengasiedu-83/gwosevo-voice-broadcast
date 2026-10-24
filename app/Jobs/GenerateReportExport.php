<?php

namespace App\Jobs;

use App\Services\Reports\Exports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $exportId) {}

    public function handle(Exports $exports): void
    {
        $exports->generate($this->exportId);
    }
}
