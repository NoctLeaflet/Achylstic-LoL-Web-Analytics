<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Riot sync schedule
|--------------------------------------------------------------------------
|
| Locally on Laragon run the scheduler with: php artisan schedule:work
|
| withoutOverlapping() keeps two runs of the same sync from colliding. The
| expiry (in minutes) frees the lock if a run is killed halfway.
|
*/

Schedule::command('riot:sync-ranks')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));

Schedule::command('riot:sync-matches')
    ->everyThirtyMinutes()
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));

// Catches up matches whose timeline (item and skill order) could not be fetched
// right when they were stored. Offset from the match sync so they do not collide.
Schedule::command('riot:sync-timelines')
    ->cron('15,45 * * * *')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));
