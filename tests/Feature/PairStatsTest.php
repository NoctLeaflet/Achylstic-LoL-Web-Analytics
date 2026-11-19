<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Services\Stats\PairStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class PairStatsTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private function stats(): PairStats
    {
        return app(PairStats::class);
    }

    public function test_it_counts_duo_games_on_the_same_team_with_the_shared_result(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([], [$a, $b], blueWins: true);   // both on team 200, which lost

        $pair = $this->stats()->among([$a->id, $b->id])[0];

        $this->assertSame([$a->id, $b->id], [$pair['a'], $pair['b']]);
        $this->assertSame(['games' => 3, 'wins' => 2, 'losses' => 1, 'winrate' => 66.7], $pair['together']);
        $this->assertSame(['games' => 0, 'wins' => 0, 'losses' => 0, 'winrate' => null], $pair['versus']);
    }

    public function test_it_counts_head_to_head_games_from_the_view_of_the_first_player(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a], [$b], blueWins: true);    // Alpha wins
        $this->addTeamGame([$a], [$b], blueWins: true);    // Alpha wins
        $this->addTeamGame([$b], [$a], blueWins: true);    // Beta wins (Beta is blue)
        $this->addTeamGame([$a], [$b], blueWins: false);   // Beta wins

        $pair = $this->stats()->among([$a->id, $b->id])[0];

        $this->assertSame(['games' => 4, 'wins' => 2, 'losses' => 2, 'winrate' => 50.0], $pair['versus']);
        $this->assertSame(0, $pair['together']['games']);
    }

    public function test_it_ignores_remakes_and_games_where_only_one_of_them_played(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a, $b], [], blueWins: true, duration: 200);   // remake
        $this->addGame($a);                                                 // Beta is not in it
        $this->addGame($b);

        $pair = $this->stats()->among([$a->id, $b->id])[0];

        $this->assertSame(0, $pair['together']['games']);
        $this->assertSame(0, $pair['versus']['games']);
    }

    public function test_it_can_be_limited_to_one_queue(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a, $b], [], blueWins: true, queueId: 420);
        $this->addTeamGame([$a, $b], [], blueWins: false, queueId: 440);

        $this->assertSame(2, $this->stats()->among([$a->id, $b->id])[0]['together']['games']);
        $this->assertSame(1, $this->stats()->among([$a->id, $b->id], RankedQueue::Solo)[0]['together']['games']);
        $this->assertSame(0.0, $this->stats()->among([$a->id, $b->id], RankedQueue::Flex)[0]['together']['winrate']);
    }

    public function test_every_pair_of_three_players_is_listed_once_with_the_lower_id_first(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');

        $this->addTeamGame([$a, $b, $c], [], blueWins: true);

        // Given out of order and with a duplicate on purpose.
        $pairs = $this->stats()->among([$c->id, $a->id, $b->id, $a->id]);

        $this->assertSame(
            [[$a->id, $b->id], [$a->id, $c->id], [$b->id, $c->id]],
            array_map(fn ($pair) => [$pair['a'], $pair['b']], $pairs),
        );
        $this->assertSame([1, 1, 1], array_map(fn ($pair) => $pair['together']['games'], $pairs));
    }

    public function test_pairs_that_never_met_are_still_listed_with_empty_records(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $pairs = $this->stats()->among([$a->id, $b->id]);

        $this->assertCount(1, $pairs);
        $this->assertNull($pairs[0]['together']['winrate']);
        $this->assertNull($pairs[0]['versus']['winrate']);
    }

    public function test_fewer_than_two_players_have_no_pairs(): void
    {
        $a = $this->makePlayer('Alpha');

        $this->assertSame([], $this->stats()->among([]));
        $this->assertSame([], $this->stats()->among([$a->id]));
    }

    public function test_players_outside_the_selection_do_not_leak_in(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');

        $this->addTeamGame([$a, $c], [$b], blueWins: true);

        $pair = $this->stats()->among([$a->id, $b->id])[0];

        $this->assertSame(0, $pair['together']['games']);
        $this->assertSame(1, $pair['versus']['games']);
    }

    public function test_for_player_lists_partners_most_played_first_from_that_players_view(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $c = $this->makePlayer('Gamma');
        $d = $this->makePlayer('Delta');   // never played with Alpha

        // Alpha + Beta: 2 duo wins. Alpha vs Gamma: 3 games, Gamma wins 2 of them.
        $this->addTeamGame([$a, $b], [], blueWins: true);
        $this->addTeamGame([$b, $a], [], blueWins: true);
        $this->addTeamGame([$a], [$c], blueWins: true);
        $this->addTeamGame([$a], [$c], blueWins: false);
        $this->addTeamGame([$c], [$a], blueWins: true);
        $this->addGame($d);

        $records = $this->stats()->forPlayer($a->id);

        $this->assertSame([$c->id, $b->id], array_column($records, 'player_id'));

        $this->assertSame(['games' => 3, 'wins' => 1, 'losses' => 2, 'winrate' => 33.3], $records[0]['versus']);
        $this->assertSame(0, $records[0]['together']['games']);

        $this->assertSame(['games' => 2, 'wins' => 2, 'losses' => 0, 'winrate' => 100.0], $records[1]['together']);
        $this->assertSame(0, $records[1]['versus']['games']);
    }

    public function test_for_player_is_symmetric_with_the_view_of_the_other_player(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');

        $this->addTeamGame([$a], [$b], blueWins: true);
        $this->addTeamGame([$a], [$b], blueWins: true);
        $this->addTeamGame([$a], [$b], blueWins: false);

        $fromA = $this->stats()->forPlayer($a->id)[0]['versus'];
        $fromB = $this->stats()->forPlayer($b->id)[0]['versus'];

        $this->assertSame([2, 1], [$fromA['wins'], $fromA['losses']]);
        $this->assertSame([1, 2], [$fromB['wins'], $fromB['losses']]);
    }

    public function test_for_player_without_shared_matches_is_empty(): void
    {
        $a = $this->makePlayer('Alpha');
        $this->addGame($a);

        $this->assertSame([], $this->stats()->forPlayer($a->id));
    }
}
