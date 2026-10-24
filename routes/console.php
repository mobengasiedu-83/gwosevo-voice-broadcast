<?php

use App\Models\ClientAuthChallenge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('broadcast:prune-events')->daily()->withoutOverlapping();

Schedule::command('broadcast:activate-scheduled')->everyMinute()->withoutOverlapping();

Schedule::command('dashboard:probe --scheduled')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => ClientAuthChallenge::where('expires_at', '<', now()->subDay())->delete())->daily()->name('client-auth-prune')->withoutOverlapping();
