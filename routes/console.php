<?php

use Illuminate\Support\Facades\Schedule;

$refreshInterval = (int) config('airoxy.token_refresh_interval', 360);
Schedule::command('airoxy:token:refresh')
    ->cron("*/{$refreshInterval} * * * *");

Schedule::command('airoxy:aggregate-stats')->dailyAt('01:00');
Schedule::command('airoxy:purge-logs')->dailyAt('01:30');
