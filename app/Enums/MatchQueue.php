<?php

namespace App\Enums;

/**
 * Every game mode whose matches can be stored and shown. Which of them are
 * actually fetched is a setting (services.riot.tracked_queues); only Solo/Duo
 * and Flex also have a rank (see RankedQueue).
 *
 * The backing value is the slug used in URLs, e.g. /players/1?queue=aram.
 */
enum MatchQueue: string
{
    case Solo = 'solo';
    case Flex = 'flex';
    case Normal = 'normal';
    case Aram = 'aram';
    case AramMayhem = 'mayhem';
    case Swiftplay = 'swiftplay';

    /** match-v5 queue id. */
    public function queueId(): int
    {
        return match ($this) {
            self::Solo => 420,
            self::Flex => 440,
            self::Normal => 400,
            self::Aram => 450,
            self::AramMayhem => 2400,
            self::Swiftplay => 480,
        };
    }

    public function slug(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Solo => 'Solo/Duo',
            self::Flex => 'Flex',
            self::Normal => 'Normal Draft',
            self::Aram => 'ARAM',
            self::AramMayhem => 'ARAM Mayhem',
            self::Swiftplay => 'Swiftplay',
        };
    }

    /** Whether the mode has a rank and LP (and therefore an LP chart and a leaderboard). */
    public function isRanked(): bool
    {
        return $this->ranked() !== null;
    }

    public function ranked(): ?RankedQueue
    {
        return match ($this) {
            self::Solo => RankedQueue::Solo,
            self::Flex => RankedQueue::Flex,
            default => null,
        };
    }

    /** Resolve a URL slug. Null for "all modes", missing and unknown values. */
    public static function fromSlug(?string $slug): ?self
    {
        return $slug === null ? null : self::tryFrom($slug);
    }

    /** Map a match-v5 queue id back to its mode, or null for any other queue. */
    public static function fromQueueId(?int $queueId): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->queueId() === $queueId) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The modes that are fetched and shown, in display order. Set in
     * services.riot.tracked_queues as a list of queue ids.
     *
     * @return list<self>
     */
    public static function tracked(): array
    {
        $ids = array_map('intval', (array) config('services.riot.tracked_queues'));

        return array_values(array_filter(self::cases(), fn (self $queue) => in_array($queue->queueId(), $ids, true)));
    }
}
