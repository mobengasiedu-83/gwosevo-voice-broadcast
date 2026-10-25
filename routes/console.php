<?php

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

Artisan::command('identity:prune', function () {
    $days = max(1, min(90, (int) config('client_auth.retention_days')));
    $count = DB::table('identity_challenges')->where('expires_at', '<', now()->subDays($days))->delete();
    $this->info('Pruned '.$count.' expired identity challenges.');
})->purpose('Remove expired identity challenges after the configured retention period');
Schedule::command('identity:prune')->daily()->withoutOverlapping();
