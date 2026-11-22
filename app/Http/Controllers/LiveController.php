<?php

namespace App\Http\Controllers;

use App\Enums\MatchQueue;
use App\Models\LiveGame;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\DataDragonService;
use Illuminate\View\View;

/**
 * Who of the group is in a game right now, with the running game, and what the
 * others did last. Reads only the database (riot:sync-live fills it every 2 minutes).
 *
 * Riot's API has no "online" status, so a player is either in a game, just
 * finished one (RECENT_MINUTES), or not playing as far as we can tell.
 */
class LiveController extends Controller
{
    /** A game that ended this recently still counts as "just played". */
    public const RECENT_MINUTES = 30;

    public function __invoke(DataDragonService $ddragon): View
    {
        $players = Player::active()->orderBy('id')->get();
        $liveByPlayer = LiveGame::current()->get()->keyBy('player_id');
        $names = $ddragon->championNames();
        $tracked = $players->pluck('name', 'puuid');

        $rows = $players->map(function (Player $player) use ($liveByPlayer) {
            $live = $liveByPlayer->get($player->id);
            $last = MatchParticipant::query()
                ->join('matches', 'matches.id', '=', 'match_participants.match_id')
                ->where('match_participants.player_id', $player->id)
                ->orderByDesc('matches.game_start_at')
                ->select('match_participants.*')
                ->with('gameMatch')
                ->first();

            $endedAt = $last?->gameMatch->game_start_at->copy()->addSeconds($last->gameMatch->game_duration);
            $recent = $endedAt !== null && $endedAt->gte(now()->subMinutes(self::RECENT_MINUTES));

            return [
                'player' => $player,
                'status' => $live ? 'in_game' : ($recent ? 'recent' : 'idle'),
                'live' => $live,
                'last' => $last === null ? null : [
                    'champion_id' => $last->champion_id,
                    'champion_name' => $last->champion_name,
                    'win' => $last->win,
                    'ended_at' => $endedAt,
                    'queue' => MatchQueue::fromQueueId($last->gameMatch->queue_id)?->label(),
                    'match_id' => $last->gameMatch->match_id,
                ],
            ];
        });

        // One panel per running game, even when several friends are in it.
        $games = $rows->filter(fn (array $row) => $row['live'] !== null)
            ->groupBy(fn (array $row) => $row['live']->game_id)
            ->map(function ($group) use ($names, $tracked) {
                $live = $group->first()['live'];
                $participants = collect($live->participants ?? []);

                return [
                    'live' => $live,
                    'queue' => MatchQueue::fromQueueId($live->queue_id)?->label() ?? 'Game',
                    'friends' => $group->pluck('player')->all(),
                    'elapsed' => max((int) $live->started_at->diffInSeconds(now(), true), 0),
                    'teams' => [
                        'Tim Biru' => $participants->where('team_id', 100)->values()->all(),
                        'Tim Merah' => $participants->where('team_id', 200)->values()->all(),
                    ],
                    'bans' => collect($live->bans ?? [])->groupBy('team_id')->map(fn ($b) => $b->pluck('champion_id')->all())->all(),
                    'names' => $names,
                    'tracked' => $tracked,
                ];
            })
            ->values();

        $order = ['in_game' => 0, 'recent' => 1, 'idle' => 2];

        return view('live', [
            'rows' => $rows->sortBy(fn (array $row) => [$order[$row['status']], $row['player']->id])->values()->all(),
            'games' => $games,
            'recentMinutes' => self::RECENT_MINUTES,
            'championNames' => $names,
        ]);
    }
}
