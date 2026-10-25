<?php

use App\Models\ClientAuthChallenge;
use App\Services\Campaigns\Recurring;
use App\Services\Integrations\Webhooks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('broadcast:prune-events')->daily()->withoutOverlapping();

Schedule::command('broadcast:activate-scheduled')->everyMinute()->withoutOverlapping();

Schedule::command('dashboard:probe --scheduled')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => ClientAuthChallenge::where('expires_at', '<', now()->subDay())->delete())->daily()->name('client-auth-prune')->withoutOverlapping();

Schedule::command('client:sync-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('reports:maintain')->everyMinute()->withoutOverlapping();

Artisan::command('campaigns:recurring', function () {
    app(Recurring::class)->process();
    $this->info('Recurring campaign slots processed.');
});
Schedule::command('campaigns:recurring')->everyMinute()->withoutOverlapping();

Artisan::command('integrations:maintain', function () {
    app(Webhooks::class)->maintain();
    $this->info('Integration outbox, delivery maintenance and retention processed.');
});
Schedule::command('integrations:maintain')->everyMinute()->withoutOverlapping();

Artisan::command('integrations:status', function () {
    $this->line('API: '.(config('integrations.api_enabled') ? 'enabled' : 'disabled'));
    $this->line('Webhook delivery: '.(config('integrations.webhook_delivery_enabled') ? 'enabled' : 'disabled'));
    $counts = DB::table('webhook_deliveries')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
    foreach ($counts as $status => $count) {
        $this->line($status.': '.$count);
    }

    return ($counts['unknown'] ?? 0) > 0 ? 1 : 0;
})->purpose('Read-only integration switches and webhook delivery counts');
