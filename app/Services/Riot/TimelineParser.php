<?php

namespace App\Services\Riot;

/**
 * Boils a match-v5 timeline down to what the build view needs per player:
 * the items they bought (in order), the order they levelled their skills, and
 * their gold, xp, cs and damage to champions after every minute.
 *
 * Participants are matched through info.participants (participantId <-> puuid).
 * The order of metadata.participants is NOT the participantId order, so it must
 * not be used for this.
 */
final class TimelineParser
{
    /**
     * @param  array<string, mixed>  $timeline  raw timeline response
     * @return array<string, array{item_purchases: list<array{0: int, 1: int}>, skill_order: list<int>, metrics: array<string, list<int>>|null}> keyed by puuid; metrics = per-minute running totals {gold, damage, xp, cs} or null
     */
    public function parse(array $timeline): array
    {
        $puuidByParticipant = [];

        foreach ($timeline['info']['participants'] ?? [] as $participant) {
            if (isset($participant['participantId'], $participant['puuid'])) {
                $puuidByParticipant[(int) $participant['participantId']] = (string) $participant['puuid'];
            }
        }

        $purchases = [];
        $skills = [];
        $metrics = [];

        foreach ($timeline['info']['frames'] ?? [] as $frame) {
            // One entry per minute (frame): running totals of gold, xp, cs and damage to champions.
            foreach ($frame['participantFrames'] ?? [] as $key => $stats) {
                $id = (int) $key;

                if (! isset($puuidByParticipant[$id])) {
                    continue;
                }

                $metrics[$id]['gold'][] = (int) ($stats['totalGold'] ?? 0);
                $metrics[$id]['damage'][] = (int) ($stats['damageStats']['totalDamageDoneToChampions'] ?? 0);
                $metrics[$id]['xp'][] = (int) ($stats['xp'] ?? 0);
                $metrics[$id]['cs'][] = (int) (($stats['minionsKilled'] ?? 0) + ($stats['jungleMinionsKilled'] ?? 0));
            }

            foreach ($frame['events'] ?? [] as $event) {
                // Shop events with participantId 0 are not a player's.
                $participantId = (int) ($event['participantId'] ?? 0);

                if (! isset($puuidByParticipant[$participantId])) {
                    continue;
                }

                switch ($event['type'] ?? '') {
                    case 'ITEM_PURCHASED':
                        $purchases[$participantId][] = [(int) round(((int) ($event['timestamp'] ?? 0)) / 1000), (int) ($event['itemId'] ?? 0)];
                        break;

                    case 'ITEM_UNDO':
                        // Undoing a purchase: the item that was bought (beforeId) disappears again.
                        $purchases[$participantId] ??= [];
                        $this->forgetLastPurchase($purchases[$participantId], (int) ($event['beforeId'] ?? 0));
                        break;

                    case 'SKILL_LEVEL_UP':
                        // EVOLVE events (Kai'Sa, Kha'Zix, Viktor) are upgrades, not skill points.
                        if (($event['levelUpType'] ?? 'NORMAL') === 'NORMAL' && in_array((int) ($event['skillSlot'] ?? 0), [1, 2, 3, 4], true)) {
                            $skills[$participantId][] = (int) $event['skillSlot'];
                        }
                        break;
                }
            }
        }

        $result = [];

        foreach ($puuidByParticipant as $participantId => $puuid) {
            $result[$puuid] = [
                'item_purchases' => array_values(array_filter($purchases[$participantId] ?? [], fn (array $purchase) => $purchase[1] > 0)),
                'skill_order' => $skills[$participantId] ?? [],
                'metrics' => $metrics[$participantId] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $purchases
     */
    private function forgetLastPurchase(array &$purchases, int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        for ($index = count($purchases) - 1; $index >= 0; $index--) {
            if (($purchases[$index][1] ?? null) === $itemId) {
                array_splice($purchases, $index, 1);

                return;
            }
        }
    }
}
