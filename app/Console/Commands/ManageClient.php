<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\Clients\Accounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

class ManageClient extends Command
{
    protected $signature = 'clients:manage {action : list, create, status or member} {--actor= : Authorized internal user ID} {--client=} {--name=} {--user=} {--status=} {--reason=} {--role=member}';

    protected $description = 'Provision client account foundations with audited internal authorization';

    public function handle(Accounts $accounts): int
    {
        $actor = User::findOrFail($this->option('actor'));
        Gate::forUser($actor)->authorize('clients.manage');
        switch ($this->argument('action')) {
            case 'list':
                $this->table(['ID', 'Name', 'Status'], Client::orderBy('id')->get(['id', 'name', 'status'])->toArray());
                break;
            case 'create':
                $client = $accounts->create($actor, (string) $this->option('name'));
                $this->info('Created pending client '.$client->id);
                break;
            case 'status':
                $accounts->status($actor, Client::findOrFail($this->option('client')), (string) $this->option('status'), (string) $this->option('reason'));
                $this->info('Account status updated.');
                break;
            case 'member':
                $accounts->member($actor, Client::findOrFail($this->option('client')), User::findOrFail($this->option('user')), (string) $this->option('role'), $this->option('status') ?: 'active');
                $this->info('Membership updated.');
                break;
            default:
                $this->error('Unknown action.');

                return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
