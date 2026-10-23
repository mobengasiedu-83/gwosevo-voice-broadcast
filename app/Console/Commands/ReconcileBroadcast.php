<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Broadcast\Reconciliation;
use Illuminate\Console\Command;

class ReconcileBroadcast extends Command
{
    protected $signature = 'broadcast:reconcile {file : JSON array of verified CDR records} {--actor= : Telephony administrator user ID} {--evidence= : Private CDR/CEL evidence reference}';

    protected $description = 'Resolve uncertain calls against CDR evidence and a fresh PBX channel snapshot';

    public function handle(Reconciliation $service): int
    {
        $file = $this->argument('file');
        $evidence = trim((string) $this->option('evidence'));
        if (! $evidence || strlen($evidence) > 1000 || ! is_file($file) || filesize($file) > 10000000) {
            $this->error('Provide a bounded JSON file and evidence reference.');

            return self::FAILURE;
        }
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('telephony.manage')) {
            $this->error('A telephony administrator actor is required.');

            return self::FAILURE;
        }
        try {
            $records = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($records) || ! array_is_list($records) || count($records) > 10000) {
                throw new \RuntimeException;
            }
            foreach ($records as $record) {
                $service->resolve($actor, $record, $evidence);
            }
        } catch (\Throwable $e) {
            $this->error('Import stopped; review the record and fresh snapshot. Previously committed records remain reconciled.');

            return self::FAILURE;
        }
        $this->info('Reconciliation complete.');

        return self::SUCCESS;
    }
}
