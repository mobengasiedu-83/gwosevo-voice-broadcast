<?php

namespace App\Jobs;

use App\Models\ContactImport;
use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\Imports;
use App\Services\Foundation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () {
            $batch = ContactImport::withoutGlobalScopes()->whereKey($this->batchId)->lockForUpdate()->first();
            if (! $batch || $batch->client_id !== $this->clientId || ! in_array($batch->status, ['queued', 'processing'])) {
                return;
            }
            app(Tenancy::class)->run($batch->client_id, function () use ($batch) {
                $batch->update(['status' => 'failed']);
                $actor = User::find($batch->owner_id);
                if ($actor) {
                    app(Foundation::class)->audit($actor, 'contacts.import_failed', $batch);
                }
            });
        });
    }
}
