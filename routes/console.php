<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('broadcast:prune-events')->daily()->withoutOverlapping();

Schedule::command('broadcast:activate-scheduled')->everyMinute()->withoutOverlapping();

Schedule::command('dashboard:probe --scheduled')->everyMinute()->withoutOverlapping();
