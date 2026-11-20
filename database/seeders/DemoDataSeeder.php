<?php

namespace Database\Seeders;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Database\Seeder;

/**
 * Fake players, rank history and matches to look at the pages before any Riot
 * data exists.
 *
 * Never run this against the real database: the players have made-up PUUIDs and
 * would make riot:sync-* fail. It refuses to run unless the database name ends
 * in "_demo", e.g.:
 *
 *   DB_DATABASE=achylstic_demo php artisan migrate --seed --seeder=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const TIERS = ['IRON', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'EMERALD', 'DIAMOND', 'MASTER'];

    private const DIVISIONS = ['IV', 'III', 'II', 'I'];

    /** champion id => champion name (Data Dragon id) */
    private const CHAMPIONS = [
        103 => 'Ahri', 238 => 'Zed', 222 => 'Jinx', 99 => 'Lux', 81 => 'Ezreal', 64 => 'LeeSin',
        412 => 'Thresh', 122 => 'Darius', 86 => 'Garen', 157 => 'Yasuo', 22 => 'Ashe', 51 => 'Caitlyn',
        89 => 'Leona', 254 => 'Vi', 61 => 'Orianna',
    ];

    private const ITEMS = [3020, 6655, 3157, 3089, 3135, 3116, 3165, 6653, 3031, 3036, 3094, 3085, 3153, 3071, 3074];

    private const POSITIONS = ['TOP', 'JUNGLE', 'MIDDLE', 'BOTTOM', 'UTILITY'];

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_demo')) {
            $this->command?->error("Refusing to seed demo data into [{$database}]. Use a database whose name ends with _demo.");

            return;
        }

        mt_srand(42);

        /** @var list<array{0: Player, 1: array<string, array{0: string, 1: string, 2: int, 3: int, 4: int}>, 2: float, 3: string}> $created */
        $created = [];

        // name, game name, profile icon, [tier, division, lp, wins, losses] per queue, win chance, mostly played position
        $people = [
            ['Raka', 'RakaGG', 29, ['solo' => ['GOLD', 'II', 54, 61, 52], 'flex' => ['SILVER', 'II', 30, 14, 12]], 0.56, 'MIDDLE'],
            ['Dewi', 'DewiMid', 588, ['solo' => ['PLATINUM', 'IV', 12, 88, 79], 'flex' => ['GOLD', 'IV', 5, 20, 18]], 0.52, 'BOTTOM'],
            ['Bima', 'BimaTop', 502, ['solo' => ['SILVER', 'I', 80, 40, 41]], 0.47, 'TOP'],
            ['Sari', 'SariSupp', 7, ['solo' => ['EMERALD', 'IV', 0, 120, 100]], 0.58, 'UTILITY'],
            ['Joko', 'JokoJgl', 1, [], 0.5, 'JUNGLE'],
        ];

        foreach ($people as [$name, $gameName, $icon, $ranks, $winChance, $position]) {
            $player = Player::create([
                'game_name' => $gameName,
                'tag_line' => 'SG2',
                'puuid' => 'demo-puuid-'.strtolower($name),
                'platform' => 'sg2',
                'display_name' => $name,
                'profile_icon_id' => $icon,
                'summoner_level' => mt_rand(80, 450),
                'is_active' => true,
            ]);

            $created[] = [$player, $ranks, $winChance, $position];
        }

        // Matches need every player to exist, so friends can show up in each other's games.
        $everyone = array_column($created, 0);

        foreach ($created as [$player, $ranks, $winChance, $position]) {
            foreach ($ranks as $slug => $current) {
                $this->seedRankHistory($player, RankedQueue::fromSlug($slug), $current, $winChance);
            }

            $this->seedMatches($player, $winChance, $position, solo: 24, flex: isset($ranks['flex']) ? 8 : 0, everyone: $everyone);
        }
    }

    /**
     * Rebuild a believable LP history that ends at the given current rank by
     * walking backwards from it, one game per snapshot.
     *
     * @param  array{0: string, 1: string, 2: int, 3: int, 4: int}  $current  tier, division, lp, wins, losses
     */
    private function seedRankHistory(Player $player, RankedQueue $queue, array $current, float $winChance): void
    {
        [$tier, $division, $lp, $wins, $losses] = $current;

        $score = $this->score($tier, $division, $lp);
        $steps = mt_rand(18, 26);
        $history = [['score' => $score, 'wins' => $wins, 'losses' => $losses]];

        for ($i = 0; $i < $steps; $i++) {
            $won = mt_rand(0, 99) < $winChance * 100;
            $score = max(0, $score + ($won ? -mt_rand(12, 24) : mt_rand(12, 22)));
            $wins -= $won ? 1 : 0;
            $losses -= $won ? 0 : 1;
            $history[] = ['score' => $score, 'wins' => max($wins, 0), 'losses' => max($losses, 0)];
        }

        $history = array_reverse($history);
        $count = count($history);

        foreach ($history as $index => $point) {
            [$snapshotTier, $snapshotDivision, $snapshotLp] = $this->fromScore($point['score']);

            $player->rankSnapshots()->create([
                'queue_type' => $queue,
                'tier' => $snapshotTier,
                'rank' => $snapshotDivision,
                'league_points' => $snapshotLp,
                'wins' => $point['wins'],
                'losses' => $point['losses'],
                'captured_at' => now()->subDays(14)->addSeconds((int) (14 * 86400 * ($index / max($count - 1, 1))) - mt_rand(0, 3600)),
            ]);
        }
    }

    /**
     * @param  list<Player>  $everyone  all demo players; about a third of the games also include one of the others,
     *                                  on the same team or on the opposing team
     */
    private function seedMatches(Player $player, float $winChance, string $position, int $solo, int $flex, array $everyone): void
    {
        $others = array_values(array_filter($everyone, fn (Player $other) => $other->id !== $player->id));

        $games = array_merge(array_fill(0, $solo, 420), array_fill(0, $flex, 440));

        foreach ($games as $index => $queueId) {
            $remake = mt_rand(0, 24) === 0;
            $duration = $remake ? 195 : mt_rand(18 * 60, 40 * 60);
            $win = ! $remake && mt_rand(0, 99) < $winChance * 100;

            $match = GameMatch::create([
                'match_id' => 'SG2_'.strtoupper(substr(md5($player->puuid.$index.$queueId), 0, 10)),
                'queue_id' => $queueId,
                'game_version' => '16.22.700.1',
                'game_mode' => 'CLASSIC',
                'game_duration' => $duration,
                'game_start_at' => now()->subDays(14)->addSeconds(mt_rand(0, 14 * 86400 - 7200)),
            ]);

            $rows = [];
            $friend = $others !== [] && mt_rand(0, 99) < 35 ? $others[array_rand($others)] : null;
            $friendOnSameTeam = (bool) mt_rand(0, 1);

            for ($slot = 0; $slot < 10; $slot++) {
                $tracked = $slot === 0;
                $team = $slot < 5 ? 100 : 200;
                // The tracked player is slot 0 (team 100); a friend sits in slot 1 (same team) or slot 5 (opposing team).
                $owner = $tracked ? $player : ($friend !== null && $slot === ($friendOnSameTeam ? 1 : 5) ? $friend : null);
                $championId = array_rand(self::CHAMPIONS);
                $minutes = $duration / 60;

                $rows[] = [
                    'player_id' => $owner?->id,
                    'puuid' => $owner?->puuid ?? 'demo-stranger-'.$match->id.'-'.$slot,
                    'riot_id_name' => $owner?->game_name ?? 'Stranger'.$slot,
                    'riot_id_tag' => 'SG2',
                    'champion_id' => $championId,
                    'champion_name' => self::CHAMPIONS[$championId],
                    'team_id' => $team,
                    'team_position' => $tracked ? $position : self::POSITIONS[$slot % 5],
                    'win' => $tracked ? $win : (($team === 100) === $win),
                    'kills' => mt_rand(0, $win ? 14 : 9),
                    'deaths' => mt_rand($win ? 0 : 2, $win ? 6 : 12),
                    'assists' => mt_rand(1, 18),
                    'total_minions_killed' => (int) ($minutes * mt_rand(40, 85) / 10),
                    'neutral_minions_killed' => mt_rand(0, 25),
                    'gold_earned' => (int) ($minutes * mt_rand(300, 420)),
                    'total_damage_dealt_to_champions' => (int) ($minutes * mt_rand(400, 900)),
                    'damage_taken' => (int) ($minutes * mt_rand(300, 700)),
                    'vision_score' => (int) ($minutes * mt_rand(5, 20) / 10),
                    'wards_placed' => mt_rand(3, 30),
                    'items' => array_map(fn () => self::ITEMS[array_rand(self::ITEMS)], range(1, 6)) + [6 => 3340],
                    'summoner_spells' => [4, 14],
                    'perks' => null,
                ];
            }

            // Per-minute running totals, as the match timeline would give them.
            $rows = array_map(fn (array $row) => $row + ['metrics' => $remake ? null : $this->curves($row, $duration)], $rows);

            $match->participants()->createMany($rows);
        }
    }

    /**
     * Made-up growth curves that end at the totals of the finished game.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, list<int>>
     */
    private function curves(array $row, int $duration): array
    {
        $frames = intdiv($duration, 60) + 1;
        $finals = [
            'gold' => $row['gold_earned'],
            'damage' => $row['total_damage_dealt_to_champions'],
            'xp' => (int) ($duration / 60 * mt_rand(380, 520)),
            'cs' => $row['total_minions_killed'] + $row['neutral_minions_killed'],
        ];
        $shape = mt_rand(90, 130) / 100;
        $series = [];

        foreach ($finals as $key => $final) {
            for ($minute = 0; $minute < $frames; $minute++) {
                $progress = $minute / ($frames - 1);
                $series[$key][] = (int) round($final * ($progress ** $shape) * (1 + mt_rand(-15, 15) / 1000));
            }
        }

        return $series;
    }

    private function score(string $tier, string $division, int $lp): int
    {
        $tierIndex = array_search($tier, self::TIERS, true);

        if ($tierIndex >= 7) {
            return 2800 + $lp;
        }

        // DIVISIONS is ordered IV, III, II, I, so the index is also the number of divisions above the bottom one.
        return $tierIndex * 400 + array_search($division, self::DIVISIONS, true) * 100 + $lp;
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function fromScore(int $score): array
    {
        if ($score >= 2800) {
            return ['MASTER', 'I', $score - 2800];
        }

        return [
            self::TIERS[intdiv($score, 400)],
            self::DIVISIONS[intdiv($score % 400, 100)],
            $score % 100,
        ];
    }
}
