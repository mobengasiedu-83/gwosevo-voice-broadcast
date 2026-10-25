<?php

namespace App\Jobs;

use App\Models\ContactImport;
use App\Services\Contacts\Imports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportContacts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public ?int $clientId;

    public function __construct(public int $batchId)
    {
        // Captured when authorized work is queued; never inferred from request data.
        $this->clientId = ContactImport::findOrFail($batchId)->client_id;
    }

    public function handle(Imports $imports): void
    {
        $batch = ContactImport::withoutGlobalScopes()->findOrFail($this->batchId);
        if ($batch->client_id !== $this->clientId) {
            return;
        }
        $imports->process($this->batchId);
    }

    public function failed(?\Throwable $e): void
    {
        ContactImport::withoutGlobalScopes()->whereKey($this->batchId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed']);
    }
}
