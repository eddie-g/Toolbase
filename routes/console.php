<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('logos:redact-base64')->dailyAt('23:55');

// Detect a dead/zombie Horizon master every minute and auto-restart it.
// withoutOverlapping prevents a slow recovery run from stacking on the next tick.
Schedule::command('ops:horizon-watchdog')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Capture Horizon metrics/wait-time snapshots for the dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
