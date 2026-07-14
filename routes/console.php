<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('connectors:sync-zoho')->hourly();
Schedule::command('queue:prune-failed --hours=72')->daily();
Schedule::command('activitylog:clean')->daily();
Schedule::command('tally:run-scheduled-exports')->everyMinute();
