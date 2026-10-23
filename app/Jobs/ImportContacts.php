<?php

namespace App\Jobs;

use App\Models\ContactImport;
use App\Services\Clients\AccountContext;
use App\Services\Contacts\Imports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportContacts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $batchId) {}

    public function handle(Imports $imports): void
    {
        $batch = ContactImport::findOrFail($this->batchId);
        if ($batch->client_account_id === null) {
            $imports->process($this->batchId);

            return;
        }
        $context = app(AccountContext::class);
        if (! $context->active((int) $batch->client_account_id)) {
            $batch->update(['status' => 'blocked']);

            return;
        }
        $context->run((int) $batch->client_account_id, fn () => $imports->process($this->batchId));
    }

    public function failed(?\Throwable $e): void
    {
        ContactImport::whereKey($this->batchId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed']);
    }
}
