<?php

namespace App\Services\Stats;

use App\Enums\MatchQueue;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Riot\DataDragonService;
use Illuminate\Support\Collection;

/**
 * Turns participant rows of tracked players into the flat "game" arrays the
 * game-row component shows: result, K/D/A, KDA, CS/min, kill participation and
 * the score with its MVP/ACE badge (MatchScore).
 *
 * The rows need their gameMatch and player relations loaded. Scores and team
 * kills are looked up with two queries for all matches together.
 */
final class GameRows
{
    /** Order of the line-up lists: top to support. */
    private const LANE_ORDER = ['TOP', 'JUNGLE', 'MIDDLE', 'BOTTOM', 'UTILITY'];

    public function __construct(
        private readonly MatchScore $scores,
        private readonly DataDragonService $ddragon,
    ) {}

    /**
     * @param  Collection<int, MatchParticipant>  $rows
     * @param  bool  $detailed  also add items, summoner spells, runes and the line-ups of both teams
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Collection $rows, bool $detailed = false): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $matchIds = $rows->pluck('match_id')->unique()->values()->all();
        $scores = $this->scores->forMatches($matchIds);
        $lineups = $detailed ? $this->lineups($matchIds) : [];
        $teamKills = MatchParticipant::query()
            ->whereIn('match_id', $matchIds)
            ->selectRaw('match_id, team_id, SUM(kills) as kills')
            ->groupBy('match_id', 'team_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->match_id}:{$r->team_id}" => (int) $r->kills]);

        return $rows->map(function (MatchParticipant $p) use ($scores, $teamKills, $lineups, $detailed) {
            $match = $p->gameMatch;
            $minutes = max($match->game_duration, 1) / 60;
            $takedowns = $p->kills + $p->assists;

            return [
                'id' => $p->id,
                'player' => $p->player,
                'match_id' => $match->match_id,
                'queue_id' => $match->queue_id,
                'queue' => MatchQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}",
                'started_at' => $match->game_start_at,
                'duration' => $match->game_duration,
                'champion_id' => $p->champion_id,
                'champion_name' => $p->champion_name,
                'role' => RoleCoverage::ROLES[$p->team_position] ?? null,
                'win' => $p->win,
                'kills' => $p->kills,
                'deaths' => $p->deaths,
                'assists' => $p->assists,
                'kda' => round($takedowns / max($p->deaths, 1), 2),
                'cs_per_min' => round(($p->total_minions_killed + $p->neutral_minions_killed) / $minutes, 1),
                'kp' => (int) round($takedowns / max($teamKills["{$p->match_id}:{$p->team_id}"] ?? 1, 1) * 100),
                'vision' => $p->vision_score,
                'score' => $scores[$p->id]['score'] ?? 0,
                'badge' => $scores[$p->id]['badge'] ?? null,
                'rank' => $scores[$p->id]['rank'] ?? null,
            ] + ($detailed ? $this->details($p, $lineups[$p->match_id] ?? []) : []);
        })->values();
    }

    /**
     * Items, spells, runes and the two line-ups of one game.
     *
     * @param  list<array<string, mixed>>  $lineup  everybody of the match
     * @return array<string, mixed>
     */
    private function details(MatchParticipant $p, array $lineup): array
    {
        $trees = $this->ddragon->runeTrees();
        $styles = $p->perks['styles'] ?? [];
        $keystoneId = (int) ($styles[0]['selections'][0]['perk'] ?? 0);
        $keystone = null;

        foreach ($trees[(int) ($styles[0]['style'] ?? 0)]['slots'][0] ?? [] as $rune) {
            if ($rune['id'] === $keystoneId) {
                $keystone = ['name' => $rune['name'], 'icon' => $this->ddragon->perkImage($rune['icon'])];
            }
        }

        $secondaryTree = $trees[(int) ($styles[1]['style'] ?? 0)] ?? null;
        $mark = fn (array $person) => $person + ['self' => $person['participant_id'] === $p->id];
        $ownTeam = fn (array $person) => $person['team_id'] === $p->team_id;

        return [
            'items' => array_slice(array_pad($p->items ?? [], 7, 0), 0, 7),
            'spells' => collect($p->summoner_spells ?? [])->take(2)->map(fn ($id) => [
                'name' => $this->ddragon->summonerSpells()[(int) $id]['name'] ?? '',
                'icon' => $this->ddragon->spellIcon((int) $id),
            ])->all(),
            'keystone' => $keystone,
            'secondary' => $secondaryTree ? ['name' => $secondaryTree['name'], 'icon' => $this->ddragon->perkImage($secondaryTree['icon'])] : null,
            'allies' => array_values(array_map($mark, array_filter($lineup, $ownTeam))),
            'enemies' => array_values(array_map($mark, array_filter($lineup, fn (array $person) => ! $ownTeam($person)))),
        ];
    }

    /**
     * The ten players of every match: tracked friends by their name, everybody else by Riot ID.
     *
     * @param  list<int>  $matchIds
     * @return array<int, list<array<string, mixed>>> by match id
     */
    private function lineups(array $matchIds): array
    {
        $participants = MatchParticipant::query()->whereIn('match_id', $matchIds)->orderBy('id')->get();
        $friends = Player::query()->whereIn('id', $participants->pluck('player_id')->filter()->unique())->get()->keyBy('id');

        return $participants->groupBy('match_id')->map(
            fn (Collection $people) => $people
                ->sortBy(fn (MatchParticipant $x) => [$x->team_id, array_search($x->team_position, self::LANE_ORDER, true) === false ? 9 : array_search($x->team_position, self::LANE_ORDER, true), $x->id])
                ->map(fn (MatchParticipant $x) => [
                    'participant_id' => $x->id,
                    'team_id' => $x->team_id,
                    'champion_id' => $x->champion_id,
                    'champion_name' => $x->champion_name,
                    'name' => $x->player_id ? $friends[$x->player_id]->name : ($x->riot_id_name ?: 'Summoner'),
                    'friend' => $x->player_id !== null,
                ])
                ->values()
                ->all(),
        )->all();
    }
}
