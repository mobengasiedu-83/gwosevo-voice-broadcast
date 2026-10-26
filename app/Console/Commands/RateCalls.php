<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\Rating;
use Illuminate\Console\Command;

class RateCalls extends Command
{
    protected $signature = 'billing:rate {file : Verified precision CDR/CEL JSON array} {--actor=} {--source=} {--evidence=}';

    protected $description = 'Reconcile trusted call timing and rate individual attempts; never debit a wallet';

    public function handle(Rating $rating): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('telephony.manage')) {
            $this->error('A telephony administrator is required.');

            return self::FAILURE;
        }
        $path = $this->argument('file');
        if (! is_file($path) || filesize($path) > 10000000) {
            $this->error('Provide a JSON file no larger than 10 MB.');

            return self::FAILURE;
        }
        $counts = ['rated' => 0, 'held' => 0];
        try {
            $rows = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
                throw new \RuntimeException;
            }
            foreach ($rows as $row) {
                $counts[$rating->import($actor, $row, (string) $this->option('source'), (string) $this->option('evidence'))]++;
            }
        } catch (\Throwable $e) {
            $this->error('Import stopped. Check authorization, source configuration, correlation, timing and channel snapshot. Previously committed records remain intact.');

            return self::FAILURE;
        }
        $this->info('Rated/replayed: '.$counts['rated'].'; held for review: '.$counts['held'].'. No wallet was debited.');

        return $counts['held'] ? self::FAILURE : self::SUCCESS;
    }
}
