<?php

namespace App\Services\Discord;

use App\Enums\MatchQueue;
use App\Events\RankChanged;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Stats\MatchScore;
use App\Services\Stats\RankScore;
use App\Support\Format;
use Illuminate\Support\Collection;

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

    private const POSITIONS = [
        'TOP' => 'Top',
        'JUNGLE' => 'Jungle',
        'MIDDLE' => 'Mid',
        'BOTTOM' => 'Bot',
        'UTILITY' => 'Support',
    ];

    public function __construct(
        private readonly MatchScore $scores,
    ) {}

    /**
     * A finished match with its tracked players. Null when none of the
     * participants is one of our players.
     *
     * @return array<string, mixed>|null
     */
    public function match(GameMatch $match): ?array
    {
        $participants = $match->participants()->whereNotNull('player_id')->with('player')->orderBy('team_id')->orderBy('id')->get();

        if ($participants->isEmpty()) {
            return null;
        }

        $everyone = $match->participants()->get();
        $scores = $this->scores->forMatch($everyone, $match->game_duration);

        $wins = $participants->where('win', true)->count();
        $allWon = $wins === $participants->count();
        $allLost = $wins === 0;
        $queue = MatchQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}";

        [$title, $color] = match (true) {
            $allWon => ['✅ Menang', self::GREEN],
            $allLost => ['❌ Kalah', self::RED],
            default => ['⚔️ Teman melawan teman', self::SKY],
        };

        return $this->embed([
            'title' => "{$title} · {$queue}",
            'description' => $participants->map(fn (MatchParticipant $p) => $this->participantBlock($p, $everyone, $match, $scores, $participants->count() > 1 && ! $allWon && ! $allLost))->implode("\n\n"),
            'color' => $color,
            'url' => $this->siteUrl('matches.show', ['gameMatch' => $match->match_id]),
            'timestamp' => $match->game_start_at->copy()->addSeconds($match->game_duration)->toIso8601String(),
            'footer' => ['text' => Format::duration($match->game_duration).$this->patch($match->game_version)],
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
            ),
            'color' => $promoted ? self::GREEN : self::RED,
            'url' => $this->siteUrl('players.show', ['player' => $event->player->id, 'queue' => $event->queue->slug()]),
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
     * One block per tracked player: champion and lane opponent, K/D/A, the per-minute
     * and per-game numbers, the score of MatchScore (with MVP/ACE).
     *
     * @param  Collection<int, MatchParticipant>  $everyone  all participants of the match
     */
    private function participantBlock(MatchParticipant $p, Collection $everyone, GameMatch $match, array $scores, bool $showResult): string
    {
        $minutes = max($match->game_duration, 1) / 60;
        $cs = $p->total_minions_killed + $p->neutral_minions_killed;
        $takedowns = $p->kills + $p->assists;
        $kda = round($takedowns / max($p->deaths, 1), 2);
        $teamKills = max((int) $everyone->where('team_id', $p->team_id)->sum('kills'), 1);
        $position = self::POSITIONS[$p->team_position] ?? null;
        $opponent = $p->team_position
            ? $everyone->first(fn (MatchParticipant $o) => $o->team_id !== $p->team_id && $o->team_position === $p->team_position)
            : null;

        $score = $scores[$p->id] ?? null;
        $lines = [sprintf(
            '%s**%s** · %s%s%s',
            $showResult ? ($p->win ? '✅ ' : '❌ ') : '',
            $this->escape($p->player->name),
            $this->escape($p->champion_name),
            $position ? " ({$position})" : '',
            $opponent ? ' vs '.$this->escape($opponent->champion_name) : '',
        )];

        $lines[] = sprintf(
            '**%d/%d/%d** · %s KDA · %s CS/min · %d CS',
            $p->kills, $p->deaths, $p->assists, Format::number($kda, 2), Format::number($cs / $minutes, 1), $cs,
        );
        $lines[] = sprintf(
            '%d%% kill participation · %s damage',
            min(100, round($takedowns / $teamKills * 100)),
            $this->compact($p->total_damage_dealt_to_champions),
        );

        if ($score !== null) {
            $lines[] = '🏅 '.($score['badge'] ? "**{$score['badge']}** · " : '')."skor {$score['score']}/100";
        }

        return implode("\n", $lines);
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
