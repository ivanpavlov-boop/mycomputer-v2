<?php

use App\Jobs\GenerateFeedJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('suppliers:run-scheduled-imports')->everyFiveMinutes();
Schedule::command('suppliers:sync-due-feeds')->everyFifteenMinutes();
Schedule::command('carts:detect-abandoned')->everyFifteenMinutes();
Schedule::command('carts:process-abandoned')->everyFifteenMinutes();
Schedule::call(fn () => GenerateFeedJob::dispatch('google_merchant'))->dailyAt('02:00');
Schedule::call(fn () => GenerateFeedJob::dispatch('facebook_catalog'))->dailyAt('02:15');
