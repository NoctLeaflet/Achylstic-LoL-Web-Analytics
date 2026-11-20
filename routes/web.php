<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\PlayerController as AdminPlayerController;
use App\Http\Controllers\Admin\SyncController;
use App\Http\Controllers\ChampionController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DuoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MatchListController;
use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

// Public pages only read from the database; every Riot API call happens in the background commands.
Route::get('/', HomeController::class)->name('home');
Route::get('/leaderboard', LeaderboardController::class)->name('leaderboard');
Route::get('/players/{player}', PlayerController::class)->name('players.show');
Route::get('/compare', CompareController::class)->name('compare');
Route::get('/matches', MatchListController::class)->name('matches.index');
Route::get('/duos', DuoController::class)->name('duos');
Route::get('/champions', ChampionController::class)->name('champions');
// Matches are addressed by their Riot match id, e.g. /matches/SG2_123456789
Route::get('/matches/{gameMatch:match_id}', MatchController::class)->name('matches.show');

// Admin area. The one place where a web request calls the Riot API (adding a friend resolves their Riot ID).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('sync', [SyncController::class, 'store'])->name('sync');
        Route::post('players', [AdminPlayerController::class, 'store'])->name('players.store');
        Route::patch('players/{player}', [AdminPlayerController::class, 'update'])->name('players.update');
        Route::patch('players/{player}/goal', [AdminPlayerController::class, 'updateGoal'])->name('players.goal');
        Route::patch('players/{player}/active', [AdminPlayerController::class, 'toggleActive'])->name('players.toggle');
    });
});
