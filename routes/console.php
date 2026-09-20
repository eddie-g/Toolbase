<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('logos:redact-base64')->dailyAt('23:55');

// Documents of visitors without an account, once their lifetime is over and
// nobody can open them any more (config pdf_editor.guests).
Schedule::command('documents:prune-guests')
    ->dailyAt('03:30')
    // Deleting is opt-in outside production (config pdf_editor.guests.prune):
    // a development database is full of ownerless documents from the QA suites
    // and fixtures opened by id, and the local container runs this scheduler.
    ->when(fn () => (bool) config('pdf_editor.guests.prune'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Expired queued exports, working files leaked by a fatal or a killed worker,
// and temp artefacts of deleted documents. onOneServer needs a shared cache.
Schedule::command('documents:cleanup-temp')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Detect a dead/zombie Horizon master every minute and auto-restart it.
// withoutOverlapping prevents a slow recovery run from stacking on the next tick.
Schedule::command('ops:horizon-watchdog')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Capture Horizon metrics/wait-time snapshots for the dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
