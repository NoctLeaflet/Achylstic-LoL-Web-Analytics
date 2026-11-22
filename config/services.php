<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Riot Games API
    |--------------------------------------------------------------------------
    |
    | The API key must only live in .env (RIOT_API_KEY) and is read through
    | config('services.riot.key'). Never expose it to the frontend, logs, or
    | JSON responses.
    |
    | platform:       routing for summoner / league / mastery (e.g. sg2, ph2)
    | region:         routing for match-v5 (e.g. sea)
    | account_region: routing for account-v1 (e.g. asia)
    | rate_limits:    request budget of a personal/dev key
    |
    */

    'riot' => [
        'key' => env('RIOT_API_KEY'),
        'platform' => env('RIOT_PLATFORM', 'sg2'),
        'region' => env('RIOT_REGION', 'sea'),
        'account_region' => env('RIOT_ACCOUNT_REGION', 'asia'),
        // Servers the admin page lets you pick when adding a friend. They all share the
        // `sea` / `asia` routing above (match-v5 / account-v1); other regions would need other routing.
        'platforms' => [
            'sg2' => 'Singapore (SG2)',
            'ph2' => 'Filipina (PH2)',
            'th2' => 'Thailand (TH2)',
            'tw2' => 'Taiwan (TW2)',
            'vn2' => 'Vietnam (VN2)',
        ],
        'rate_limits' => [
            'per_second' => 20,
            'per_two_minutes' => 100,
        ],
        // Game modes whose matches are fetched, stored and shown (match-v5 queue ids):
        // 420 Solo/Duo, 440 Flex, 400 Normal Draft, 450 ARAM.
        // Add 480 for Swiftplay. Every mode costs one extra request per player per sync.
        //
        // 2400 (ARAM Mayhem) is NOT tracked: when checked on 2026-11-22, Riot's match-v5 returned
        // no ARAM Mayhem game for any of the 9 players (queue=2400 gave 0 ids, and none of their 280
        // newest games had that queue). Add 2400 back here if Riot starts returning them.
        'tracked_queues' => [420, 440, 400, 450],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Dragon (static assets: champion, item and profile icons)
    |--------------------------------------------------------------------------
    |
    | A public CDN that needs no API key and has no relation to the Riot API
    | rate limits. The latest game version is fetched from versions.json and cached.
    |
    */

    'ddragon' => [
        'base_url' => 'https://ddragon.leagueoflegends.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Discord webhook notifications
    |--------------------------------------------------------------------------
    |
    | The webhook URL contains a secret token: keep it in .env only (never log
    | it, never commit it). Leave DISCORD_WEBHOOK_URL empty to turn notifications off.
    |
    | max_match_age_minutes:  matches that ended longer ago than this are never
    |                         announced, so adding a player does not flood the
    |                         channel with their match history.
    | win_streak_milestones:  a win streak is announced when it reaches exactly one
    |                         of these lengths (so a 4th or 6th win stays quiet).
    |
    */

    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'max_match_age_minutes' => 180,
        'win_streak_milestones' => [3, 5, 7, 10, 15, 20],
    ],

    /*
    |--------------------------------------------------------------------------
    | Season recap
    |--------------------------------------------------------------------------
    |
    | season_start: first day of the current ranked season (YYYY-MM-DD). The recap page counts
    |               games from this day. Set SEASON_START in .env when a new season begins;
    |               without it the recap starts on 1 January of the current year.
    |
    */

    'recap' => [
        'season_start' => env('SEASON_START'),
    ],

];
