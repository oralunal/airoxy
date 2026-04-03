<?php

use Illuminate\Support\Facades\Schedule;

$refreshMinutes = (int) config('airoxy.token_refresh_interval', 360);
$refreshHours = max(1, intdiv($refreshMinutes, 60));
Schedule::command('airoxy:token:refresh')
    ->cron("0 */{$refreshHours} * * *");

Schedule::command('airoxy:aggregate-stats')->dailyAt('01:00');
Schedule::command('airoxy:purge-logs')->dailyAt('01:30');
