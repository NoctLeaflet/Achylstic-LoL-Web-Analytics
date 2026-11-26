<?php

namespace App\Services\Discord;

use App\Enums\MatchQueue;
use App\Enums\RankedQueue;
use App\Events\RankChanged;
use App\Models\Achievement;
use App\Models\GameMatch;
use App\Models\LiveGame;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use App\Services\Achievements\AchievementCatalog;
use App\Services\Riot\DataDragonService;
use App\Services\Stats\MatchScore;
use App\Services\Stats\RankScore;
use App\Services\Stats\WeeklyHighlights;
use App\Services\Stats\WinStreak;
use App\Support\Format;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds the Discord embeds. Pure formatting: nothing is sent from here.
 *
 * Names come from Riot and from the group, so they are escaped for Discord
 * markdown. The links only appear when APP_URL is reachable from the internet
 * (a localhost link would be useless in a Discord channel).
 */
final class DiscordMessages
{
    private const GREEN = 0x22C55E;

    private const RED = 0xEF4444;

    private const SLATE = 0x64748B;

    private const SKY = 0x38BDF8;

    private const AMBER = 0xF59E0B;

    /** A win or loss streak of this length gets its own kind of joke. */
    private const QUIP_STREAK = 3;

    private const POSITIONS = [
        'TOP' => 'Top',
        'JUNGLE' => 'Jungle',
        'MIDDLE' => 'Mid',
        'BOTTOM' => 'Bot',
        'UTILITY' => 'Support',
    ];

    public function __construct(
        private readonly MatchScore $scores,
        private readonly DataDragonService $ddragon,
        private readonly QuipPicker $quips,
        private readonly WinStreak $streaks,
    ) {}

    /**
     * A finished match as one card per tracked player, so players are clearly
     * separated: result, champion and lane opponent, a joke, and the numbers.
     * Empty when none of the participants is one of our players.
     *
     * @return list<array<string, mixed>>
     */
    public function matchCards(GameMatch $match): array
    {
        $tracked = $match->participants()->whereNotNull('player_id')->with('player')->orderBy('team_id')->orderBy('id')->get()
            ->sortBy(fn (MatchParticipant $p) => [$p->team_id, array_search($p->team_position, array_keys(self::POSITIONS), true), $p->id])
            ->values();

        if ($tracked->isEmpty()) {
            return [];
        }

        $everyone = $match->participants()->get();
        $scores = $this->scores->forMatch($everyone, $match->game_duration);
        $names = $this->championNames();
        $usedQuips = [];
        $cards = [];

        foreach ($tracked as $index => $p) {
            $card = $this->playerCard($p, $everyone, $match, $scores, $names, $usedQuips);

            // Discord merges embeds that share a URL, so every card after the first gets its own.
            $url = $this->siteUrl('matches.show', ['gameMatch' => $match->match_id] + ($index > 0 ? ['p' => $p->id] : []));
            $card['url'] = $url;

            if ($index === $tracked->count() - 1) {
                // The moment the game ended.
                $card['timestamp'] = $match->game_start_at->copy()->addSeconds($match->game_duration)->toIso8601String();
            }

            $cards[] = $this->embed($card);
        }

        return $cards;
    }

    /**
     * The Monday summary: climber, standout games and awards of the last 7 days.
     * Null when nobody played, so there is nothing to say.
     *
     * @param  array{best: list<array<string, mixed>>, awards: list<array<string, mixed>>, games: int}  $weekly  WeeklyHighlights::build()
     * @param  array{player: Player, gain: int, from: string, to: string}|null  $climber  ClimberOfTheWeek::climber()
     * @return array<string, mixed>|null
     */
    public function weekly(array $weekly, ?array $climber, RankedQueue $queue): ?array
    {
        if ($weekly['games'] === 0) {
            return null;
        }

        $fields = [];

        if ($climber !== null) {
            $fields[] = [
                'name' => '🧗 Top climber',
                'value' => sprintf('**%s** +%d LP di %s (%s → %s)', $this->escape($climber['player']->name), $climber['gain'], $queue->label(), $climber['from'], $climber['to']),
            ];
        }

        if ($weekly['best'] !== []) {
            $fields[] = [
                'name' => '⭐ Game menonjol',
                'value' => collect($weekly['best'])->map(function (array $item) {
                    $g = $item['game'];

                    return sprintf(
                        '**%s** · %s · %s (%s) %d/%d/%d · skor %d%s',
                        $item['label'],
                        $this->escape($g['player']->name),
                        $this->escape($g['champion_name']),
                        $g['win'] ? 'win' : 'lose',
                        $g['kills'], $g['deaths'], $g['assists'],
                        $g['score'],
                        $g['badge'] ? " ({$g['badge']})" : '',
                    );
                })->implode("\n"),
            ];
        }

        if ($weekly['awards'] !== []) {
            $fields[] = [
                'name' => '🏆 Penghargaan',
                'value' => collect($weekly['awards'])
                    ->map(fn (array $award) => sprintf('**%s**: %s (%s)', $award['title'], $this->escape($award['player']->name), $award['value']))
                    ->implode("\n"),
            ];
        }

        return $this->embed([
            'title' => '📅 Rangkuman minggu ini',
            'description' => "{$weekly['games']} game dimainkan dalam ".WeeklyHighlights::DAYS.' hari terakhir.',
            'color' => self::AMBER,
            'url' => $this->siteUrl('leaderboard', []),
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function winStreak(Player $player, int $streak): array
    {
        return $this->embed([
            'title' => "🔥 Win streak {$streak}!",
            'description' => "**{$this->escape($player->name)}** menang {$streak} game berturut-turut.",
            'color' => self::AMBER,
            'url' => $this->siteUrl('players.show', ['player' => $player->id]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rankChange(RankChanged $event): array
    {
        $promoted = $event->promoted();
        $from = RankScore::tierLabel($event->previous->tier, $event->previous->rank);
        $to = RankScore::tierLabel($event->current->tier, $event->current->rank);

        $quip = $this->quips->pick(
            [$promoted ? 'promoted' : 'demoted'],
            ['name' => $this->escape($event->player->name), 'tier' => $to],
        );

        return $this->embed([
            'title' => $promoted ? '⬆️ Naik rank!' : '⬇️ Turun rank',
            'description' => sprintf(
                '**%s** %s dari **%s** ke **%s** di Ranked %s (%d LP).',
                $this->escape($event->player->name),
                $promoted ? 'naik' : 'turun',
                $from,
                $to,
                $event->queue->label(),
                $event->current->league_points,
            ).($quip ? "\n💬 *{$quip}*" : ''),
            'color' => $promoted ? self::GREEN : self::RED,
            'url' => $this->siteUrl('players.show', ['player' => $event->player->id, 'queue' => $event->queue->slug()]),
        ]);
    }

    /**
     * Friends who just started the same game.
     *
     * @param  Collection<int, LiveGame>  $rows  live games of one game id, with their player loaded
     * @return array<string, mixed>
     */
    public function liveGame(Collection $rows): array
    {
        $names = $this->championNames();
        $queue = MatchQueue::fromQueueId($rows->first()->queue_id)?->label();

        return $this->embed([
            'title' => '🎮 Sedang bermain'.($queue ? " · {$queue}" : ''),
            'description' => $rows->map(fn (LiveGame $row) => '**'.$this->escape($row->player->name).'**'
                .($row->champion_id && isset($names[$row->champion_id]) ? ' · '.$this->escape($names[$row->champion_id]) : ''))->implode("\n"),
            'color' => self::SKY,
            'url' => $this->siteUrl('leaderboard', []),
        ]);
    }

    /**
     * New achievements of one player.
     *
     * @param  list<Achievement>  $achievements
     * @return array<string, mixed>
     */
    public function achievements(Player $player, array $achievements): array
    {
        $catalog = AchievementCatalog::all();

        return $this->embed([
            'title' => '🏅 Pencapaian baru!',
            'description' => "**{$this->escape($player->name)}** meraih:\n".collect($achievements)
                ->map(fn (Achievement $a) => "{$catalog[$a->code]['icon']} **{$catalog[$a->code]['name']}**: {$catalog[$a->code]['description']}")
                ->implode("\n"),
            'color' => self::AMBER,
            'url' => $this->siteUrl('players.show', ['player' => $player->id]),
        ]);
    }

    /**
     * The nightly database backup failed.
     *
     * @return array<string, mixed>
     */
    public function backupFailed(string $reason): array
    {
        return $this->embed([
            'title' => '⚠️ Backup database gagal',
            'description' => "{$reason}\nJalankan `php artisan db:backup` dari terminal untuk melihat detailnya.",
            'color' => self::RED,
        ]);
    }

    /**
     * The Riot API key was rejected (expired or invalid). Sent once per episode.
     *
     * @return array<string, mixed>
     */
    public function keyRejected(int $status): array
    {
        return $this->embed([
            'title' => '⚠️ API key Riot ditolak',
            'description' => "Riot menjawab HTTP {$status}: key kedaluwarsa atau tidak valid. Sinkronisasi rank dan match berhenti sampai key diganti.\n"
                .'Ambil key baru di developer.riotgames.com, isi `RIOT_API_KEY` di file `.env`, lalu jalankan sinkron lagi dari halaman admin.',
            'color' => self::RED,
            'url' => $this->siteUrl('admin.dashboard', []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function test(): array
    {
        return $this->embed([
            'title' => '✅ Webhook terhubung',
            'description' => config('app.name').' akan mengirim notifikasi match, naik atau turun rank, dan win streak ke channel ini.',
            'color' => self::SKY,
        ]);
    }

    /**
     * The card of one tracked player: result, champion and lane opponent as the
     * title, a joke, and K/D/A, CS, damage, score (with MVP/ACE or the place in
     * the match) and the current rank as fields.
     *
     * @param  Collection<int, MatchParticipant>  $everyone  all participants of the match
     * @param  array<int, array{score: int, badge: string|null, rank: int}>  $scores
     * @param  array<int, string>  $names  champion display names by champion id
     * @param  list<string>  $usedQuips
     * @return array<string, mixed>
     */
    private function playerCard(MatchParticipant $p, Collection $everyone, GameMatch $match, array $scores, array $names, array &$usedQuips): array
    {
        $minutes = max($match->game_duration, 1) / 60;
        $cs = $p->total_minions_killed + $p->neutral_minions_killed;
        $takedowns = $p->kills + $p->assists;
        $kda = round($takedowns / max($p->deaths, 1), 2);
        $teamKills = max((int) $everyone->where('team_id', $p->team_id)->sum('kills'), 1);
        $topDamage = max((int) $everyone->max('total_damage_dealt_to_champions'), 1);
        $position = self::POSITIONS[$p->team_position] ?? null;
        $opponent = $p->team_position
            ? $everyone->first(fn (MatchParticipant $o) => $o->team_id !== $p->team_id && $o->team_position === $p->team_position)
            : null;
        $champion = $this->escape($names[$p->champion_id] ?? $p->champion_name);
        $enemy = $opponent ? $this->escape($names[$opponent->champion_id] ?? $opponent->champion_name) : null;
        $score = $scores[$p->id] ?? null;

        $quip = $this->quip($p, $match, $score['badge'] ?? null, $kda, $cs / $minutes, $champion, $enemy, $usedQuips);

        $fields = [
            $this->field("{$p->kills}/{$p->deaths}/{$p->assists}", '*'.Format::number($kda, 2).' KDA*'),
            $this->field(Format::number($cs / $minutes, 1).' CS/min', '*'.min(100, round($takedowns / $teamKills * 100)).'% kill participation*'),
            $this->field($this->compact($p->total_damage_dealt_to_champions).' damage', $this->bar($p->total_damage_dealt_to_champions / $topDamage)),
        ];

        if ($score !== null) {
            // MVP and ACE say it all; everybody else gets their place among all players.
            $fields[] = $this->field("🏅 Skor {$score['score']}", $score['badge'] ? "**{$score['badge']}**" : "#{$score['rank']} dari {$everyone->count()}");
        }

        $rank = $this->currentRank($p->player_id, $match->queue_id);

        if ($rank !== null) {
            $fields[] = $this->field('Rank', $rank);
        }

        $queue = MatchQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}";
        $profileIcon = $this->image(fn () => $this->ddragon->profileIcon($p->player->profile_icon_id));
        $championIcon = $this->image(fn () => $this->ddragon->championIcon($p->champion_name, $p->champion_id));

        return [
            // Discord does not read markdown in the author name, so it is not escaped.
            'author' => array_filter(['name' => $p->player->name, 'icon_url' => $profileIcon]),
            'title' => ($p->win ? '✅ Win' : '❌ Lose').' · '.$champion.($position ? " ({$position})" : '').($enemy ? " vs {$enemy}" : ''),
            'description' => $quip ? "💬 *{$quip}*" : null,
            'color' => $p->win ? self::GREEN : self::RED,
            'thumbnail' => $championIcon ? ['url' => $championIcon] : null,
            'fields' => $fields,
            'footer' => ['text' => $queue.' · '.Format::duration($match->game_duration).$this->patch($match->game_version)],
        ];
    }

    /**
     * The joke of a card, from the most notable thing about the performance down
     * to a plain win or loss.
     *
     * @param  list<string>  $used
     */
    private function quip(MatchParticipant $p, GameMatch $match, ?string $badge, float $kda, float $csPerMinute, string $champion, ?string $enemy, array &$used): ?string
    {
        $winStreak = $p->win ? $this->streaks->endingAt($p->player_id, $match) : 0;
        $lossStreak = $p->win ? 0 : $this->streaks->lossesEndingAt($p->player_id, $match);

        $categories = array_keys(array_filter([
            'pentakill' => $p->penta_kills > 0,
            'feeding' => $p->deaths >= 10 && $kda < 1,
            'carry_loss' => ! $p->win && $badge === MatchScore::ACE,
            'stomp_win' => $p->win && $kda >= 5,
            'win_streak' => $winStreak >= self::QUIP_STREAK,
            'loss_streak' => $lossStreak >= self::QUIP_STREAK,
            'long_game' => $match->game_duration > 40 * 60,
            'short_game' => $match->game_duration < 20 * 60,
            // Supports are not meant to farm, and modes without lanes have no meaningful CS.
            'low_cs' => $p->team_position && $p->team_position !== 'UTILITY' && $csPerMinute < 4.5,
            ($p->win ? 'win' : 'loss') => true,
        ]));

        return $this->quips->pick($categories, [
            'name' => $this->escape($p->player->name),
            'champ' => $champion,
            'enemy' => $enemy,
            'kills' => $p->kills,
            'deaths' => $p->deaths,
            'assists' => $p->assists,
            'kda' => Format::number($kda, 2),
            'cs' => Format::number($csPerMinute, 1),
            'streak' => max($winStreak, $lossStreak) ?: null,
            'minutes' => (int) floor($match->game_duration / 60),
            // The LP change of a single game is unknown (ranks are synced separately),
            // so lines that need {lp} are skipped rather than made up.
            'lp' => null,
        ], $used);
    }

    /** "Gold II · 54 LP" from the latest snapshot, for ranked games only. */
    private function currentRank(int $playerId, int $queueId): ?string
    {
        $queue = RankedQueue::fromQueueId($queueId);

        if ($queue === null) {
            return null;
        }

        $snapshot = RankSnapshot::query()
            ->where('player_id', $playerId)
            ->where('queue_type', $queue->value)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        return $snapshot ? RankScore::label($snapshot->tier, $snapshot->rank, $snapshot->league_points) : null;
    }

    /** @return array{name: string, value: string, inline: bool} */
    private function field(string $name, string $value): array
    {
        return ['name' => $name, 'value' => $value, 'inline' => true];
    }

    /** A text bar of 8 blocks for a share between 0 and 1. */
    private function bar(float $share): string
    {
        $filled = (int) round(max(0, min(1, $share)) * 8);

        return str_repeat('▰', $filled).str_repeat('▱', 8 - $filled);
    }

    /** A Data Dragon image URL, or null when it cannot be had (the card then goes without it). */
    private function image(callable $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Champion display names from Data Dragon (cached); empty when it cannot be reached.
     *
     * @return array<int, string>
     */
    private function championNames(): array
    {
        try {
            return $this->ddragon->championNames();
        } catch (Throwable) {
            return [];
        }
    }

    /** 48623 -> "48.6k" */
    private function compact(int $value): string
    {
        return $value >= 1000 ? Format::number($value / 1000, 1).'k' : (string) $value;
    }

    private function patch(?string $version): string
    {
        return $version ? ' · Patch '.implode('.', array_slice(explode('.', $version), 0, 2)) : '';
    }

    /** Escape Discord markdown so a name like "*Boss*" cannot change the formatting. */
    private function escape(string $text): string
    {
        return preg_replace('/([\\\\*_~`|>#\[\]()])/', '\\\\$1', $text);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function siteUrl(string $route, array $parameters): ?string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $local = $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost');

        return $local ? null : route($route, $parameters);
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function embed(array $embed): array
    {
        return array_filter($embed, fn ($value) => $value !== null);
    }
}
