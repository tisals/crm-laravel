<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh the dashboard KPI snapshot nightly at 3am (off-peak).
// Reads serve from a single SELECT instead of 70+ aggregations → sub-50ms
// dashboard load. Manual trigger: `php artisan crm:refresh-dashboard-snapshot`.
Schedule::command('crm:refresh-dashboard-snapshot')
    ->dailyAt('03:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Refresh the user_identity_snapshot (CQRS-Lite read model for /me/identity).
// The /me/identity endpoint serves from a single row instead of joining
// 6 tables on every request → sub-80ms p95 cache-hit. Manual trigger:
// `php artisan crm:refresh-user-identity-snapshot --user=ID`.
Schedule::command('crm:refresh-user-identity-snapshot')
    ->dailyAt('03:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
