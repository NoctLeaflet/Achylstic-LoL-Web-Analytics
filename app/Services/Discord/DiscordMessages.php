<?php

namespace App\Services\Discord;

use App\Enums\RankedQueue;
use App\Events\RankChanged;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Services\Stats\RankScore;
use App\Support\Format;

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

        $wins = $participants->where('win', true)->count();
        $allWon = $wins === $participants->count();
        $allLost = $wins === 0;
        $queue = RankedQueue::fromQueueId($match->queue_id)?->label() ?? "Queue {$match->queue_id}";

        [$title, $color] = match (true) {
            $allWon => ['✅ Menang', self::GREEN],
            $allLost => ['❌ Kalah', self::RED],
            default => ['⚔️ Teman melawan teman', self::SKY],
        };

        return $this->embed([
            'title' => "{$title} · {$queue}",
            'description' => $participants->map(fn (MatchParticipant $p) => $this->participantLine($p, $participants->count() > 1 && ! $allWon && ! $allLost))->implode("\n"),
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
            'description' => "**{$this->escape($player->name)}** menang {$streak} game ranked berturut-turut.",
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

    private function participantLine(MatchParticipant $p, bool $showResult): string
    {
        $cs = $p->total_minions_killed + $p->neutral_minions_killed;
        $kda = round(($p->kills + $p->assists) / max($p->deaths, 1), 2);
        $position = self::POSITIONS[$p->team_position] ?? null;

        return sprintf(
            '%s**%s** · %s%s · %d/%d/%d · %s KDA · %d CS',
            $showResult ? ($p->win ? '✅ ' : '❌ ') : '',
            $this->escape($p->player->name),
            $this->escape($p->champion_name),
            $position ? " ({$position})" : '',
            $p->kills,
            $p->deaths,
            $p->assists,
            Format::number($kda, 2),
            $cs,
        );
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
