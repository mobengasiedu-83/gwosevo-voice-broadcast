<?php

namespace App\Services\Clients;

use App\Models\Client;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Batch ownership labels for records already visible in an administrator view. */
class AdminIdentity
{
    private array $prepared = [];

    private array $clients = [];

    private array $campaigns = [];

    private array $contacts = [];

    private array $recipients = [];

    private array $attempts = [];

    public function prepare(array $data): void
    {
        $records = [];
        $walk = function ($value) use (&$walk, &$records) {
            if ($value instanceof Paginator) {
                $walk($value->items());
            } elseif (is_array($value) || $value instanceof Collection) {
                foreach ($value as $item) {
                    $walk($item);
                }
            } elseif ($value instanceof Model || $value instanceof \stdClass) {
                $records[] = $value;
            }
        };
        $walk($data);
        if (! $records) {
            return;
        }
        $signature = implode(',', array_map(fn ($r) => (string) spl_object_id($r), $records));
        if (isset($this->prepared[$signature])) {
            return;
        }
        $this->prepared[$signature] = $records;
        $ids = fn ($field) => array_values(array_unique(array_filter(array_map(fn ($r) => $r->$field ?? null, $records))));
        $this->attempts += DB::table('call_attempts')->whereIn('id', array_merge($ids('call_attempt_id'), $ids('attempt_id')))->pluck('campaign_recipient_id', 'id')->all();
        $this->recipients += DB::table('campaign_recipients')->whereIn('id', array_merge($ids('campaign_recipient_id'), array_values($this->attempts)))->pluck('campaign_id', 'id')->all();
        $this->campaigns += DB::table('campaigns')->whereIn('id', array_merge($ids('campaign_id'), array_values($this->recipients)))->pluck('client_id', 'id')->all();
        $this->contacts += DB::table('contacts')->whereIn('id', $ids('contact_id'))->pluck('client_id', 'id')->all();
        $clientIds = array_merge($ids('client_id'), array_values($this->campaigns), array_values($this->contacts));
        foreach ($records as $record) {
            if ($record instanceof Client) {
                $clientIds[] = $record->id;
            }
        }
        foreach (Client::withDisplayEmail()->whereIn('clients.id', array_filter($clientIds))->get() as $client) {
            $this->clients[$client->id] = $client->name.' · '.$client->display_email;
        }
    }

    public function label($record): string
    {
        $recipient = $record->campaign_recipient_id ?? ($this->attempts[$record->call_attempt_id ?? $record->attempt_id ?? 0] ?? null);
        $campaign = $record->campaign_id ?? ($this->recipients[$recipient ?? 0] ?? null);
        $id = $record instanceof Client ? $record->id : ($record->client_id
            ?? $this->campaigns[$campaign ?? 0] ?? $this->contacts[$record->contact_id ?? 0] ?? null);

        return $id ? ($this->clients[$id] ?? 'Client #'.$id.' · No email') : 'Staff-owned · No client';
    }
}
