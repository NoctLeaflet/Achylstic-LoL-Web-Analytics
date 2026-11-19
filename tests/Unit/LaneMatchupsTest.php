<?php

namespace Tests\Unit;

use App\Services\Stats\LaneMatchups;
use PHPUnit\Framework\TestCase;

class LaneMatchupsTest extends TestCase
{
    private int $nextId = 1;

    /**
     * A participant in the shape MatchScoreboard produces.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function player(string $name, ?string $position, array $overrides = []): array
    {
        return array_merge([
            'id' => $this->nextId++,
            'name' => $name,
            'champion_name' => 'Ahri',
            'champion_id' => 103,
            'position' => $position,
            'win' => true,
            'score' => 50,
            'badge' => null,
            'kills' => 5, 'deaths' => 5, 'assists' => 5,
            'kda' => 2.0,
            'cs' => 300,
            'gold' => 15000,
            'damage' => 24000,
            'vision' => 30,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $blue
     * @param  list<array<string, mixed>>  $red
     * @return list<array<string, mixed>>
     */
    private function teams(array $blue, array $red, int $blueKills = 25, int $redKills = 25): array
    {
        return [
            ['team_id' => 100, 'kills' => $blueKills, 'participants' => $blue],
            ['team_id' => 200, 'kills' => $redKills, 'participants' => $red],
        ];
    }

    private function matchups(array $teams, int $duration = 1800): array
    {
        return (new LaneMatchups)->build($teams, $duration);
    }

    public function test_each_role_that_exists_once_per_team_becomes_a_matchup_in_lane_order(): void
    {
        $teams = $this->teams(
            [$this->player('B-Sup', 'UTILITY'), $this->player('B-Top', 'TOP'), $this->player('B-Mid', 'MIDDLE')],
            [$this->player('R-Mid', 'MIDDLE'), $this->player('R-Sup', 'UTILITY'), $this->player('R-Top', 'TOP')],
        );

        $matchups = $this->matchups($teams);

        $this->assertSame(['TOP', 'MIDDLE', 'UTILITY'], array_column($matchups, 'position'));
        $this->assertSame(['Top', 'Mid', 'Support'], array_column($matchups, 'label'));
        $this->assertSame('B-Top', $matchups[0]['blue']['name']);
        $this->assertSame('R-Top', $matchups[0]['red']['name']);
        $this->assertSame('B-Sup', $matchups[2]['blue']['name']);
        $this->assertSame('R-Sup', $matchups[2]['red']['name']);
    }

    public function test_roles_missing_on_one_side_or_filled_twice_are_skipped(): void
    {
        $teams = $this->teams(
            [$this->player('B-Top', 'TOP'), $this->player('B-Jg', 'JUNGLE'), $this->player('B-Bot1', 'BOTTOM'), $this->player('B-Bot2', 'BOTTOM')],
            [$this->player('R-Top', 'TOP'), $this->player('R-Bot', 'BOTTOM')],
        );

        $this->assertSame(['TOP'], array_column($this->matchups($teams), 'position'));
    }

    public function test_modes_without_lanes_and_remakes_have_no_matchups(): void
    {
        $aram = $this->teams(
            [$this->player('B1', null), $this->player('B2', null)],
            [$this->player('R1', null), $this->player('R2', null)],
        );
        $this->assertSame([], $this->matchups($aram));

        $normal = $this->teams([$this->player('B', 'TOP')], [$this->player('R', 'TOP')]);
        $this->assertCount(1, $this->matchups($normal));
        $this->assertSame([], $this->matchups($normal, duration: 299));   // a remake
        $this->assertSame([], $this->matchups([]));
        $this->assertSame([], $this->matchups([['team_id' => 100, 'kills' => 1, 'participants' => []]]));
    }

    public function test_the_radar_scales_every_axis_to_the_better_player_and_keeps_the_real_values(): void
    {
        $teams = $this->teams(
            [$this->player('Blue', 'TOP', ['kda' => 4.0, 'kills' => 8, 'assists' => 2, 'damage' => 30000, 'gold' => 18000, 'cs' => 360, 'vision' => 12])],
            [$this->player('Red', 'TOP', ['kda' => 2.0, 'kills' => 4, 'assists' => 6, 'damage' => 15000, 'gold' => 18000, 'cs' => 240, 'vision' => 30])],
            blueKills: 20,
            redKills: 40,
        );

        $radar = $this->matchups($teams)[0]['radar'];

        $this->assertSame(['KDA', 'Partisipasi kill %', 'Damage/mnt', 'Gold/mnt', 'CS/mnt', 'Vision/mnt'], $radar['labels']);

        [$blue, $red] = $radar['datasets'];
        // Player name only, no champion.
        $this->assertSame('Blue', $blue['label']);
        $this->assertSame('Red', $red['label']);
        $this->assertSame('#38bdf8', $blue['color']);
        $this->assertSame('#fb7185', $red['color']);

        // 30 min game. KDA 4.0 vs 2.0 -> 100 / 50.
        $this->assertSame([100.0, 50.0], [$blue['values'][0], $red['values'][0]]);
        // Kill participation: blue (8+2)/20 = 50%, red (4+6)/40 = 25%.
        $this->assertSame([50.0, 25.0], [$blue['raw'][1], $red['raw'][1]]);
        $this->assertSame([100.0, 50.0], [$blue['values'][1], $red['values'][1]]);
        // Damage per minute: 1000 vs 500.
        $this->assertSame([1000.0, 500.0], [$blue['raw'][2], $red['raw'][2]]);
        // Equal gold: both are the best, both 100.
        $this->assertSame([100.0, 100.0], [$blue['values'][3], $red['values'][3]]);
        // CS per minute 12.0 vs 8.0 -> 100 / 66.7.
        $this->assertSame([12.0, 8.0], [$blue['raw'][4], $red['raw'][4]]);
        $this->assertSame([100.0, 66.7], [$blue['values'][4], $red['values'][4]]);
        // Vision per minute 0.4 vs 1.0 -> red leads.
        $this->assertSame([40.0, 100.0], [$blue['values'][5], $red['values'][5]]);
    }

    public function test_the_table_rows_name_the_winner_of_each_metric(): void
    {
        $teams = $this->teams(
            [$this->player('Blue', 'UTILITY', ['kda' => 6.5, 'kills' => 1, 'assists' => 20, 'damage' => 6000, 'gold' => 9000, 'cs' => 30, 'vision' => 90])],
            [$this->player('Red', 'UTILITY', ['kda' => 3.25, 'kills' => 0, 'assists' => 13, 'damage' => 9000, 'gold' => 9000, 'cs' => 30, 'vision' => 60])],
            blueKills: 25,
            redKills: 25,
        );

        $matchup = $this->matchups($teams)[0];

        $byLabel = array_column($matchup['rows'], null, 'label');

        $this->assertSame(['label' => 'KDA', 'blue' => '6.50', 'red' => '3.25', 'winner' => 'blue'], $byLabel['KDA']);
        $this->assertSame('red', $byLabel['Damage/mnt']['winner']);
        $this->assertNull($byLabel['Gold/mnt']['winner']);   // a tie has no winner
        $this->assertSame('blue', $byLabel['Vision/mnt']['winner']);
        $this->assertSame('3.00', $byLabel['Vision/mnt']['blue']);   // 90 over 30 minutes
        $this->assertSame('1.0', $byLabel['CS/mnt']['blue']);

        // Blue leads KDA, kill participation and vision; red leads damage; gold and CS are ties.
        $this->assertSame(['blue' => 3, 'red' => 1], $matchup['leads']);
    }

    public function test_players_with_nothing_do_not_divide_by_zero(): void
    {
        $empty = ['kills' => 0, 'deaths' => 0, 'assists' => 0, 'kda' => 0.0, 'cs' => 0, 'gold' => 0, 'damage' => 0, 'vision' => 0];
        $teams = $this->teams([$this->player('B', 'TOP', $empty)], [$this->player('R', 'TOP', $empty)], blueKills: 0, redKills: 0);

        $matchup = $this->matchups($teams)[0];

        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $matchup['radar']['datasets'][0]['values']);
        $this->assertSame([0, 0], array_values($matchup['leads']));
    }

    public function test_the_sides_carry_what_the_popup_header_shows(): void
    {
        $teams = $this->teams(
            [$this->player('Blue', 'TOP', ['champion_name' => 'Renekton', 'champion_id' => 58, 'score' => 77, 'badge' => 'MVP', 'win' => true])],
            [$this->player('Red', 'TOP', ['win' => false, 'badge' => 'ACE'])],
        );

        $matchup = $this->matchups($teams)[0];

        $this->assertSame(['Renekton', 58, 77, 'MVP', true], [$matchup['blue']['champion_name'], $matchup['blue']['champion_id'], $matchup['blue']['score'], $matchup['blue']['badge'], $matchup['blue']['win']]);
        $this->assertSame(['ACE', false], [$matchup['red']['badge'], $matchup['red']['win']]);
    }
}
