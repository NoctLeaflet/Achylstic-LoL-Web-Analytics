<?php

use App\Http\Controllers\CompareController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

// Pages only read from the database; every Riot API call happens in the background commands.
Route::get('/', LeaderboardController::class)->name('leaderboard');
Route::get('/players/{player}', PlayerController::class)->name('players.show');
Route::get('/compare', CompareController::class)->name('compare');
// Matches are addressed by their Riot match id, e.g. /matches/SG2_123456789
Route::get('/matches/{gameMatch:match_id}', MatchController::class)->name('matches.show');
