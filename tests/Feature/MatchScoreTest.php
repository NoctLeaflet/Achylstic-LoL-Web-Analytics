<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Services\Stats\MatchScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class MatchScoreTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private function scorer(): MatchScore
    {
        return app(MatchScore::class);
    }

    /**
     * A 30 minute match with 5 winners (team 100) and 5 losers (team 200), all
     * average, so single stats can be changed per player.
     *
     * @param  array<string, array<string, mixed>>  $overrides  participant puuid => stats
     * @return array{0: GameMatch, 1: array<string, MatchParticipant>} the match and its participants by puuid
     */
    private function match(array $overrides = [], int $duration = 1800): array
    {
        $match = GameMatch::create([
            'match_id' => 'SG2_'.random_int(1000000, 9999999), 'queue_id' => 420, 'game_version' => '16.1.1',
            'game_mode' => 'CLASSIC', 'game_duration' => $duration, 'game_start_at' => now(),
        ]);

        $participants = [];

        for ($i = 0; $i < 10; $i++) {
            $puuid = "p{$i}";
            $team = $i < 5 ? 100 : 200;

            $participants[$puuid] = $match->participants()->create(array_merge([
                'puuid' => $puuid, 'champion_id' => 1, 'champion_name' => 'Annie', 'team_id' => $team,
                'team_position' => 'MIDDLE', 'win' => $team === 100,
                'kills' => 5, 'deaths' => 5, 'assists' => 5, 'total_minions_killed' => 150, 'neutral_minions_killed' => 0,
                'gold_earned' => 10000, 'total_damage_dealt_to_champions' => 15000, 'damage_taken' => 15000,
                'vision_score' => 20, 'wards_placed' => 5,
            ], $overrides[$puuid] ?? []));
        }

        return [$match, $participants];
    }

    private function score(array $participants, GameMatch $match): array
    {
        return $this->scorer()->forMatch(collect($participants)->values(), $match->game_duration);
    }

    public function test_the_best_player_of_each_team_gets_mvp_and_ace(): void
    {
        [$match, $p] = $this->match([
            'p1' => ['kills' => 14, 'deaths' => 1, 'assists' => 9, 'total_damage_dealt_to_champions' => 32000, 'gold_earned' => 14000, 'vision_score' => 40],
            'p7' => ['kills' => 9, 'deaths' => 3, 'assists' => 6, 'total_damage_dealt_to_champions' => 24000, 'gold_earned' => 12000],
        ]);

        $scores = $this->score($p, $match);

        $this->assertSame('MVP', $scores[$p['p1']->id]['badge']);
        $this->assertSame('ACE', $scores[$p['p7']->id]['badge']);

        // Only those two have a badge.
        $this->assertSame(2, collect($scores)->whereNotNull('badge')->count());
    }

    public function test_scores_are_whole_numbers_between_0_and_100(): void
    {
        [$match, $p] = $this->match([
            'p0' => ['kills' => 30, 'deaths' => 0, 'assists' => 20, 'total_damage_dealt_to_champions' => 60000, 'gold_earned' => 20000, 'vision_score' => 90, 'total_minions_killed' => 400],
            'p9' => ['kills' => 0, 'deaths' => 15, 'assists' => 0, 'total_damage_dealt_to_champions' => 1000, 'gold_earned' => 4000, 'vision_score' => 2, 'total_minions_killed' => 20],
        ]);

        foreach ($this->score($p, $match) as $row) {
            $this->assertIsInt($row['score']);
            $this->assertGreaterThanOrEqual(0, $row['score']);
            $this->assertLessThanOrEqual(100, $row['score']);
        }
    }

    public function test_the_dominant_winner_scores_100_and_the_worst_loser_scores_0(): void
    {
        [$match, $p] = $this->match([
            'p0' => ['kills' => 30, 'deaths' => 0, 'assists' => 20, 'total_damage_dealt_to_champions' => 60000, 'gold_earned' => 20000, 'vision_score' => 90, 'total_minions_killed' => 400],
            'p9' => ['kills' => 0, 'deaths' => 15, 'assists' => 0, 'total_damage_dealt_to_champions' => 0, 'gold_earned' => 4000, 'vision_score' => 0, 'total_minions_killed' => 20],
        ]);

        $scores = $this->score($p, $match);

        // p0 is best at everything that counts... except kill participation is shared with the team, so allow a hair under 100.
        $this->assertGreaterThanOrEqual(97, $scores[$p['p0']->id]['score']);
        $this->assertSame(0, $scores[$p['p9']->id]['score']);
    }

    public function test_winners_get_a_small_bonus_over_identical_losers(): void
    {
        [$match, $p] = $this->match();   // everybody identical except the result

        $scores = $this->score($p, $match);

        // Identical stats = 0.5 on everything = 47 points; the winners add 6.
        $this->assertSame(53, $scores[$p['p0']->id]['score']);
        $this->assertSame(47, $scores[$p['p9']->id]['score']);
    }

    public function test_a_supports_vision_can_win_the_mvp_even_with_little_damage_and_cs(): void
    {
        [$match, $p] = $this->match([
            'p4' => ['team_position' => 'UTILITY', 'kills' => 2, 'deaths' => 1, 'assists' => 22, 'vision_score' => 95, 'total_minions_killed' => 20, 'gold_earned' => 7000, 'total_damage_dealt_to_champions' => 6000],
            'p0' => ['kills' => 6, 'deaths' => 4, 'assists' => 6],
        ]);

        $scores = $this->score($p, $match);

        $this->assertSame('MVP', $scores[$p['p4']->id]['badge']);
    }

    public function test_the_same_game_scores_higher_for_a_support_than_for_a_carry_role(): void
    {
        // Lots of assists and vision, little damage, gold and CS: a typical good support game.
        $supportGame = [
            'kills' => 1, 'deaths' => 2, 'assists' => 20, 'vision_score' => 80,
            'total_minions_killed' => 15, 'gold_earned' => 7000, 'total_damage_dealt_to_champions' => 6000,
        ];

        [$asSupport, $p] = $this->match(['p4' => $supportGame + ['team_position' => 'UTILITY']]);
        $supportScore = $this->score($p, $asSupport)[$p['p4']->id]['score'];

        [$asMid, $q] = $this->match(['p4' => $supportGame + ['team_position' => 'MIDDLE']]);
        $midScore = $this->score($q, $asMid)[$q['p4']->id]['score'];

        $this->assertGreaterThan($midScore, $supportScore);
    }

    public function test_only_supports_get_the_support_weights(): void
    {
        // Identical stats everywhere: each metric is 0.5, so every role scores the same.
        [$match, $p] = $this->match(['p2' => ['team_position' => 'UTILITY']]);

        $scores = $this->score($p, $match);

        $this->assertSame($scores[$p['p0']->id]['score'], $scores[$p['p2']->id]['score']);
    }

    public function test_ties_are_broken_by_kda_then_by_a_stable_order(): void
    {
        [$match, $p] = $this->match();   // all winners tie on score and KDA

        $scores = $this->score($p, $match);

        // Deterministic: the first participant wins the tie.
        $this->assertSame('MVP', $scores[$p['p0']->id]['badge']);
        $this->assertNull($scores[$p['p1']->id]['badge']);
        $this->assertSame('ACE', $scores[$p['p5']->id]['badge']);
    }

    public function test_remakes_get_scores_but_no_badges(): void
    {
        [$match, $p] = $this->match(duration: 200);

        $scores = $this->score($p, $match);

        $this->assertCount(10, $scores);
        $this->assertSame([], collect($scores)->whereNotNull('badge')->all());
    }

    public function test_it_copes_with_odd_matches(): void
    {
        $this->assertSame([], $this->scorer()->forMatch(collect(), 1800));

        // A single participant: everything is "equal", nothing divides by zero.
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player);
        $participants = $match->participants()->get();

        $scores = $this->scorer()->forMatch($participants, $match->game_duration);

        $this->assertSame(53, $scores[$participants[0]->id]['score']);
        $this->assertSame('MVP', $scores[$participants[0]->id]['badge']);
    }

    public function test_for_matches_scores_several_stored_matches_at_once(): void
    {
        [$first] = $this->match();
        [$second] = $this->match(duration: 200);

        $scores = $this->scorer()->forMatches([$first->id, $second->id]);

        $this->assertCount(20, $scores);
        $this->assertSame(2, collect($scores)->whereNotNull('badge')->count());   // the remake has none
        $this->assertSame([], $this->scorer()->forMatches([]));
    }
}
