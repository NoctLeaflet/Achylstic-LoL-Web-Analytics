<?php

namespace Tests\Feature;

use App\Http\Controllers\MatchListController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class MatchListPageTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    public function test_it_lists_every_game_of_every_active_player_newest_first(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $hidden = $this->makePlayer('Ghost', ['is_active' => false]);
        $old = $this->addGame($alpha, ['champion_name' => 'Garen'], startedAt: now()->subDays(3));
        $new = $this->addGame($beta, ['champion_name' => 'Jinx'], startedAt: now()->subHour());
        $this->addGame($hidden);
        $this->addGame($alpha, [], duration: 200);   // remake

        $response = $this->get('/matches')->assertOk();

        $response->assertSeeInOrder([route('matches.show', $new->match_id), route('matches.show', $old->match_id)], false);
        $this->assertSame(2, $response->viewData('total'));
        $response->assertDontSee('Ghost');
    }

    public function test_it_can_filter_by_player_and_mode(): void
    {
        $alpha = $this->makePlayer('Alpha');
        $beta = $this->makePlayer('Beta');
        $this->addGame($alpha, [], queueId: 420);
        $this->addGame($alpha, [], queueId: 400);
        $this->addGame($beta, [], queueId: 400);

        $this->assertSame(1, $this->get("/matches?player={$alpha->id}&mode=solo")->viewData('total'));
        $this->assertSame(2, $this->get('/matches?mode=normal')->viewData('total'));
        $this->assertSame(2, $this->get("/matches?player={$alpha->id}")->viewData('total'));
        // Unknown values mean no filter.
        $this->assertSame(3, $this->get('/matches?player=999&mode=nonsense')->viewData('total'));
    }

    public function test_it_shows_most_played_champions_and_roles(): void
    {
        $alpha = $this->makePlayer('Alpha');
        foreach (range(1, 3) as $i) {
            $this->addGame($alpha, ['champion_name' => 'Jinx', 'champion_id' => 222, 'team_position' => 'BOTTOM']);
        }
        $this->addGame($alpha, ['champion_name' => 'Garen', 'champion_id' => 86, 'team_position' => 'TOP', 'win' => false]);

        $summary = $this->get('/matches')->assertOk()->viewData('summary');

        $this->assertSame('Jinx', $summary['champions'][0]['champion_name']);
        $this->assertSame(3, $summary['champions'][0]['games']);
        $this->assertSame(100, $summary['champions'][0]['winrate']);
        $this->assertSame(['Alpha'], $summary['champions'][0]['players']);
        $this->assertSame('Bot', $summary['roles'][0]['label']);
        $this->assertSame(75, $summary['roles'][0]['share']);
    }

    public function test_it_paginates(): void
    {
        $alpha = $this->makePlayer('Alpha');
        foreach (range(1, MatchListController::PER_PAGE + 2) as $i) {
            $this->addGame($alpha);
        }

        $this->get('/matches')->assertOk()->assertSee('Halaman 1 dari 2');
        $second = $this->get('/matches?page=2')->assertOk();
        $this->assertCount(2, $second->viewData('paginator')->items());
    }

    public function test_the_nav_links_to_the_list_and_an_empty_list_renders(): void
    {
        $this->get('/matches')
            ->assertOk()
            ->assertSee('href="'.route('matches.index').'"', false)
            ->assertSee('Belum ada match untuk pilihan ini');
    }

    public function test_friends_who_played_the_same_match_are_framed_together(): void
    {
        $players = array_map(fn ($n) => $this->makePlayer("P{$n}"), range(1, 3));
        $solo = $this->makePlayer('Solo');
        $this->addTeamGame($players, [], true, startedAt: now()->subHours(2));     // three friends, one team
        $this->addGame($solo, [], startedAt: now()->subHours(5));                   // an ordinary single game

        $html = $this->get('/matches')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'role="group"'));
        $this->assertStringContainsString('3 teman main bareng', $html);
        $this->assertStringContainsString('border-sky-400/60', $html);              // all won: blue frame
        // The three rows sit inside the group, the single game does not get one.
        $this->assertSame(4, substr_count($html, 'href="'.url('/matches/SG2_')));
    }

    public function test_friends_against_each_other_are_marked_as_such_and_a_lost_group_is_red(): void
    {
        [$a, $b, $c, $d] = array_map(fn ($n) => $this->makePlayer("F{$n}"), range(1, 4));
        $this->addTeamGame([$a], [$b], true, startedAt: now()->subHours(2));        // friends on opposite teams
        $this->addTeamGame([$c, $d], [], false, startedAt: now()->subHours(6));     // two friends lose together

        $html = $this->get('/matches')->assertOk()->getContent();

        $this->assertStringContainsString('Teman melawan teman', $html);
        $this->assertStringContainsString('border-rose-400/60', $html);
        $this->assertSame(2, substr_count($html, 'role="group"'));
    }

    public function test_every_row_shows_the_place_of_its_player_by_score(): void
    {
        [$a, $b, $c] = array_map(fn ($n) => $this->makePlayer("R{$n}"), range(1, 3));
        $match = $this->addTeamGame([$a, $b], [$c], true);
        $match->participants()->where('player_id', $b->id)->update(['kills' => 25, 'assists' => 15, 'deaths' => 0]);

        $games = $this->get('/matches')->assertOk()->viewData('days')->flatten(1)->keyBy(fn ($g) => $g['player']->id);

        $this->assertSame(1, $games[$b->id]['rank']);
        $this->assertGreaterThan(1, $games[$a->id]['rank']);
        $this->assertGreaterThan(1, $games[$c->id]['rank']);
        $page = $this->get('/matches')->assertSee('Urutan skor di match ini');
        $page->assertDontSee('#1', false);   // the MVP (best player) shows the badge instead of a place
        $page->assertSee('#'.$games[$a->id]['rank'], false);
    }

    public function test_rows_show_items_spells_runes_and_both_line_ups(): void
    {
        [$a, $b, $c] = array_map(fn ($n) => $this->makePlayer("L{$n}"), range(1, 3));
        $this->addTeamGame([$a, $b], [$c], true);

        $game = $this->get('/matches')->assertOk()->viewData('days')->flatten(1)->firstWhere('player.id', $a->id);

        $this->assertCount(7, $game['items']);
        $this->assertCount(2, $game['spells']);
        $this->assertSame(['L1', 'L2'], array_column($game['allies'], 'name'));
        $this->assertSame(['L3'], array_column($game['enemies'], 'name'));
        $this->assertTrue($game['allies'][0]['self']);
        $this->assertFalse($game['allies'][1]['self']);
        $this->get('/matches')->assertSee('aria-label="Line-up"', false);
    }

    public function test_current_streaks_are_listed_with_their_length(): void
    {
        $winner = $this->makePlayer('Winner');
        $loser = $this->makePlayer('Loser');
        $short = $this->makePlayer('Short');
        foreach ([[$winner, [false, true, true, true]], [$loser, [true, false, false]], [$short, [false, true]]] as [$player, $results]) {
            foreach ($results as $i => $win) {
                $this->addGame($player, ['win' => $win], startedAt: now()->subHours(10 - $i));
            }
        }

        $streaks = $this->get('/matches')->assertOk()->assertSee('Streak sekarang')->viewData('streaks');

        $this->assertSame([['Winner', 3]], collect($streaks['wins'])->map(fn ($s) => [$s['player']->name, $s['games']])->all());
        $this->assertSame([['Loser', 2]], collect($streaks['losses'])->map(fn ($s) => [$s['player']->name, $s['games']])->all());
    }

    public function test_streaks_follow_the_game_mode_filter(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['win' => true], queueId: 420, startedAt: now()->subHours(5));
        $this->addGame($player, ['win' => true], queueId: 420, startedAt: now()->subHours(4));
        $this->addGame($player, ['win' => false], queueId: 400, startedAt: now()->subHours(1));

        $this->assertCount(0, $this->get('/matches')->viewData('streaks')['wins']);
        $this->assertCount(1, $this->get('/matches?mode=solo')->viewData('streaks')['wins']);
    }

    public function test_roles_carry_wins_and_losses(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addGame($player, ['team_position' => 'BOTTOM', 'win' => true]);
        $this->addGame($player, ['team_position' => 'BOTTOM', 'win' => true]);
        $this->addGame($player, ['team_position' => 'BOTTOM', 'win' => false]);

        $bot = $this->get('/matches')->assertOk()->viewData('summary')['roles'][0];

        $this->assertSame([2, 1], [$bot['wins'], $bot['losses']]);
        $this->get('/matches')->assertSee('2W')->assertSee('1L');
    }
}
