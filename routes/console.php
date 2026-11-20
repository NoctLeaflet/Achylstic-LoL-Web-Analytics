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

// Every Monday morning (app timezone): the summary of the past week in Discord.
// It only sends when DISCORD_WEBHOOK_URL is set and somebody played.
Schedule::command('discord:weekly')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));

// A nightly dump of the database (the LP history cannot be fetched from Riot again).
// The newest BACKUP_KEEP dumps are kept in storage/app/backups.
Schedule::command('db:backup')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));

// Who is in a game right now (spectator-v5). 9 players = 9 requests per run, far below the key's budget.
Schedule::command('riot:sync-live')
    ->everyTwoMinutes()
    ->withoutOverlapping(5)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));

// Champion mastery changes slowly: once a day is plenty.
Schedule::command('riot:sync-mastery')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/riot-sync.log'));
