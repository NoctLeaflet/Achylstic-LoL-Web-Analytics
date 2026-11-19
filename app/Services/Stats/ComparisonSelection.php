<?php

namespace App\Services\Stats;

use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Parses the players of /compare?players=1,2,3.
 */
final class ComparisonSelection
{
    public const MIN = 2;

    public const MAX = 5;

    /**
     * Existing players in the order they were given. Unknown, invalid and
     * duplicate ids are dropped. The MAX limit is left to the caller so it can
     * tell the visitor that some players were left out.
     *
     * @return Collection<int, Player>
     */
    public function players(?string $csv): Collection
    {
        $ids = collect(explode(',', (string) $csv))
            ->map(fn (string $id) => (int) trim($id))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $found = Player::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (int $id) => $found->get($id))->filter()->values();
    }
}
