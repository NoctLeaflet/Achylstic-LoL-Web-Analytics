<?php

namespace App\Enums;

/**
 * The ranked queues we track. The backing value is the `queueType` string
 * returned by league-v4; queueId() is the numeric id used by match-v5.
 */
enum RankedQueue: string
{
    case Solo = 'RANKED_SOLO_5x5';
    case Flex = 'RANKED_FLEX_SR';

    public function queueId(): int
    {
        return match ($this) {
            self::Solo => 420,
            self::Flex => 440,
        };
    }

    /** Short identifier used in URLs, e.g. /?queue=solo */
    public function slug(): string
    {
        return match ($this) {
            self::Solo => 'solo',
            self::Flex => 'flex',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Solo => 'Solo/Duo',
            self::Flex => 'Flex',
        };
    }

    /** Resolve a URL slug, falling back to Solo/Duo for missing or unknown values. */
    public static function fromSlug(?string $slug): self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return self::Solo;
    }

    /** Map a match-v5 queue id (420, 440) back to the queue, or null for any other queue. */
    public static function fromQueueId(?int $queueId): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->queueId() === $queueId) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<int> */
    public static function queueIds(): array
    {
        return array_map(fn (self $queue) => $queue->queueId(), self::cases());
    }
}
