<?php

namespace App\Console\Commands;

use App\Models\AudioFile;
use App\Services\Clients\Portal;
use Illuminate\Console\Command;

class SyncClientReviewNotices extends Command
{
    protected $signature = 'client:sync-review-notices';

    protected $description = 'Idempotently import current client recording review decisions into portal notifications';

    public function handle(Portal $portal): int
    {
        AudioFile::whereNotNull('client_account_id')->whereIn('status', ['approved', 'rejected'])->chunkById(250, function ($files) use ($portal) {
            foreach ($files as $audio) {
                $portal->reviewNotice($audio);
            }
        });
        $this->info('Current client recording decisions synchronized.');

        return self::SUCCESS;
    }
}
