<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expire stale pending reservations every minute so learners see updated capacity promptly
Schedule::command('reservations:expire-pending')->everyMinute();

// Mark confirmed reservations as no-shows after the check-in window closes
Schedule::command('reservations:mark-noshows')->everyMinute();

// Daily backup at 02:00 server time; --actor resolved inside the command
Schedule::command('backups:run --type=daily')->dailyAt('02:00');
