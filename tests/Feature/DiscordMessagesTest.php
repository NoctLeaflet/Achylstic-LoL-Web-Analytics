<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Events\RankChanged;
use App\Services\Discord\DiscordMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class DiscordMessagesTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Champion and profile icons come from Data Dragon; nothing real is requested.
        Cache::put('ddragon.version', '16.1.1');
        Http::fake();
    }

    private function messages(): DiscordMessages
    {
        return app(DiscordMessages::class);
    }

    public function test_a_won_match_gives_one_card_per_player_with_their_numbers(): void
    {
        $this->freezeTime();
        config(['quips.stomp_win' => ['{name} hebat pakai {champ}, KDA {kda}.']]);
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'II', 54);
        $match = $this->addGame($player, [
            'champion_name' => 'Ahri', 'team_position' => 'MIDDLE', 'win' => true,
            'kills' => 8, 'deaths' => 2, 'assists' => 10, 'total_minions_killed' => 270, 'neutral_minions_killed' => 30,
        ], startedAt: now()->subHour());

        $cards = $this->messages()->matchCards($match);

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('Ayam', $card['author']['name']);
        $this->assertSame('✅ Win · Ahri (Mid)', $card['title']);
        $this->assertSame('💬 *Ayam hebat pakai Ahri, KDA 9.00.*', $card['description']);
        $this->assertSame(
            [
                ['8/2/10', '*9.00 KDA*'],
                ['10.0 CS/min', '*100% kill participation*'],
                ['20.0k damage', '▰▰▰▰▰▰▰▰'],
                ['🏅 Skor 75', '**MVP**'],
                ['Rank', 'Gold II · 54 LP'],
            ],
            array_map(fn (array $field) => [$field['name'], $field['value']], $card['fields']),
        );
        $this->assertSame(0x22C55E, $card['color']);
        $this->assertSame('Solo/Duo · 30:00 · Patch 16.1', $card['footer']['text']);
        $this->assertStringContainsString('/img/champion/', $card['thumbnail']['url']);
        // The timestamp is the moment the game ended.
        $this->assertSame(now()->subHour()->addSeconds(1800)->toIso8601String(), $card['timestamp']);
    }

    public function test_a_lost_match_is_red_and_normal_games_show_no_rank(): void
    {
        $player = $this->makePlayer('Alpha');
        $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'IV', 10);
        $match = $this->addGame($player, ['win' => false], queueId: 400);

        $card = $this->messages()->matchCards($match)[0];

        $this->assertStringStartsWith('❌ Lose · Ahri', $card['title']);
        $this->assertSame(0xEF4444, $card['color']);
        $this->assertNotContains('Rank', array_column($card['fields'], 'name'));
    }

    public function test_every_friend_gets_a_card_and_only_the_badge_holders_have_no_place(): void
    {
        [$a, $b, $c] = array_map(fn ($n) => $this->makePlayer($n), ['Alpha', 'Beta', 'Gamma']);
        $match = $this->addTeamGame([$a, $b], [$c], blueWins: true);

        $cards = $this->messages()->matchCards($match);

        $this->assertCount(3, $cards);
        $this->assertSame([0x22C55E, 0x22C55E, 0xEF4444], array_column($cards, 'color'));
        $scoreFields = array_map(fn (array $card) => collect($card['fields'])->first(fn ($f) => str_starts_with($f['name'], '🏅'))['value'], $cards);
        $this->assertContains('**MVP**', $scoreFields);
        $this->assertContains('**ACE**', $scoreFields);
        $this->assertCount(1, array_filter($scoreFields, fn (string $value) => preg_match('/^#\d dari 3$/', $value)));
        // Only the last card carries the time.
        $this->assertSame([false, false, true], array_map(fn (array $card) => isset($card['timestamp']), $cards));
    }

    public function test_friends_in_one_game_get_different_jokes(): void
    {
        config(['quips' => ['win' => ['Satu {name}', 'Dua {name}', 'Tiga {name}']]]);
        $players = array_map(fn ($n) => $this->makePlayer($n), ['Alpha', 'Beta', 'Gamma']);
        $match = $this->addTeamGame($players, [], blueWins: true, stats: ['kills' => 1, 'deaths' => 5, 'assists' => 1]);

        $jokes = array_map(fn (array $card) => explode(' ', $card['description'])[1], $this->messages()->matchCards($match));

        $this->assertCount(3, array_unique($jokes));
    }

    public function test_the_most_notable_thing_picks_the_kind_of_joke(): void
    {
        config(['quips' => [
            'pentakill' => ['penta'], 'feeding' => ['feeding'], 'carry_loss' => ['carry'], 'stomp_win' => ['stomp'],
            'win_streak' => ['winstreak {streak}'], 'loss_streak' => ['lossstreak {streak}'], 'long_game' => ['long {minutes}'],
            'short_game' => ['short'], 'low_cs' => ['lowcs {cs}'], 'win' => ['win'], 'loss' => ['loss'],
        ]]);
        $joke = function (array $stats, int $duration = 1800): string {
            $player = $this->makePlayer('P'.uniqid());
            $match = $this->addGame($player, $stats, duration: $duration);

            return $this->messages()->matchCards($match)[0]['description'];
        };
        $average = ['kills' => 3, 'deaths' => 4, 'assists' => 4];

        $this->assertSame('💬 *penta*', $joke(['penta_kills' => 1, 'kills' => 20, 'deaths' => 1]));
        $this->assertSame('💬 *feeding*', $joke(['kills' => 1, 'deaths' => 12, 'assists' => 2]));
        $this->assertSame('💬 *stomp*', $joke(['kills' => 10, 'deaths' => 1, 'assists' => 5]));
        // A lone player on the losing team is its ACE.
        $this->assertSame('💬 *carry*', $joke(['win' => false] + $average));
        $this->assertSame('💬 *long 45*', $joke($average, 2700));
        $this->assertSame('💬 *short*', $joke($average, 1000));
        $this->assertSame('💬 *lowcs 1.0*', $joke(['total_minions_killed' => 30, 'neutral_minions_killed' => 0] + $average));
        $this->assertSame('💬 *win*', $joke(['team_position' => 'UTILITY', 'total_minions_killed' => 30] + $average));
    }

    public function test_streaks_get_their_own_joke(): void
    {
        config(['quips' => ['win_streak' => ['winstreak {streak}'], 'win' => ['win']]]);
        $player = $this->makePlayer('Alpha');
        $stats = ['kills' => 3, 'deaths' => 4, 'assists' => 4];
        $this->addGame($player, $stats, startedAt: now()->subHours(3));
        $this->addGame($player, $stats, startedAt: now()->subHours(2));
        $third = $this->addGame($player, $stats, startedAt: now()->subHour());

        $this->assertSame('💬 *winstreak 3*', $this->messages()->matchCards($third)[0]['description']);
    }

    public function test_a_joke_that_needs_unknown_numbers_is_never_used(): void
    {
        config(['quips' => ['win' => ['+{lp} LP buat {name}', '{enemy} nangis', 'aman {name}']]]);
        // No lane opponent and the LP change of one game is unknown: only the last line can be filled.
        $match = $this->addGame($this->makePlayer('Alpha'), ['kills' => 3, 'deaths' => 4, 'assists' => 4]);

        $this->assertSame('💬 *aman Alpha*', $this->messages()->matchCards($match)[0]['description']);
    }

    public function test_every_shipped_joke_only_uses_known_placeholders(): void
    {
        $known = ['name', 'champ', 'enemy', 'kills', 'deaths', 'assists', 'kda', 'cs', 'lp', 'tier', 'streak', 'minutes'];
        $categories = ['win', 'loss', 'stomp_win', 'carry_loss', 'feeding', 'low_cs', 'pentakill', 'short_game', 'long_game', 'win_streak', 'loss_streak', 'promoted', 'demoted'];

        foreach ($categories as $category) {
            $this->assertNotEmpty(config("quips.{$category}"), $category);

            foreach (config("quips.{$category}") as $line) {
                preg_match_all('/\{(\w+)\}/', $line, $matches);
                $this->assertSame([], array_diff($matches[1], $known), $line);
            }
        }
    }

    public function test_only_tracked_players_appear_and_matches_without_any_are_skipped(): void
    {
        $a = $this->makePlayer('Alpha');
        $match = $this->addGame($a);
        $match->participants()->create([
            'player_id' => null, 'puuid' => 'stranger', 'riot_id_name' => 'Stranger', 'champion_id' => 1, 'champion_name' => 'Annie',
            'team_id' => 200, 'win' => false, 'kills' => 1, 'deaths' => 1, 'assists' => 1, 'total_minions_killed' => 1,
            'neutral_minions_killed' => 0, 'gold_earned' => 1, 'total_damage_dealt_to_champions' => 1, 'damage_taken' => 1,
            'vision_score' => 1, 'wards_placed' => 1,
        ]);

        $cards = $this->messages()->matchCards($match);
        $this->assertCount(1, $cards);
        $this->assertSame('Alpha', $cards[0]['author']['name']);

        $match->participants()->whereNotNull('player_id')->delete();
        $this->assertSame([], $this->messages()->matchCards($match->fresh()));
    }

    public function test_markdown_in_names_is_escaped(): void
    {
        config(['quips' => ['win' => ['{name}']]]);
        $player = $this->makePlayer('Evil', ['display_name' => '*Boss*_x [link](http://a.b) @everyone']);
        $match = $this->addGame($player, ['kills' => 3, 'deaths' => 4, 'assists' => 4]);

        $description = $this->messages()->matchCards($match)[0]['description'];

        $this->assertStringContainsString('\*Boss\*\_x \[link\]\(http://a.b\) @everyone', $description);
    }

    public function test_links_are_left_out_for_a_local_site_url(): void
    {
        foreach (['http://localhost:8000', 'http://127.0.0.1:8000', 'http://achylstic.test', 'http://pc.local'] as $url) {
            config(['app.url' => $url]);
            $player = $this->makePlayer('P'.crc32($url));
            $match = $this->addGame($player);

            $this->assertArrayNotHasKey('url', $this->messages()->matchCards($match)[0], $url);
            $this->assertArrayNotHasKey('url', $this->messages()->winStreak($player, 3), $url);
        }
    }

    public function test_links_point_to_the_site_and_differ_per_card_when_it_is_public(): void
    {
        config(['app.url' => 'https://tracker.example.com']);
        URL::forceRootUrl('https://tracker.example.com');
        URL::forceScheme('https');

        [$a, $b] = [$this->makePlayer('Alpha'), $this->makePlayer('Beta')];
        $match = $this->addTeamGame([$a, $b], [], blueWins: true);

        $urls = array_column($this->messages()->matchCards($match), 'url');

        $this->assertSame("https://tracker.example.com/matches/{$match->match_id}", $urls[0]);
        // Discord would merge two embeds with the same URL into one.
        $this->assertStringStartsWith("https://tracker.example.com/matches/{$match->match_id}?p=", $urls[1]);
        $this->assertSame("https://tracker.example.com/players/{$a->id}", $this->messages()->winStreak($a, 3)['url']);
    }

    public function test_a_win_streak_message(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);

        $embed = $this->messages()->winStreak($player, 5);

        $this->assertSame('🔥 Win streak 5!', $embed['title']);
        $this->assertSame('**Ayam** menang 5 game berturut-turut.', $embed['description']);
        $this->assertSame(0xF59E0B, $embed['color']);
    }

    public function test_rank_change_messages_for_a_promotion_and_a_demotion(): void
    {
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $gold1 = $this->addSnapshot($player, RankedQueue::Solo, 'GOLD', 'I', 90);
        $plat4 = $this->addSnapshot($player, RankedQueue::Solo, 'PLATINUM', 'IV', 12);

        $up = $this->messages()->rankChange(new RankChanged($player, RankedQueue::Solo, $gold1, $plat4));
        $this->assertSame('⬆️ Naik rank!', $up['title']);
        $this->assertStringStartsWith('**Ayam** naik dari **Gold I** ke **Platinum IV** di Ranked Solo/Duo (12 LP).', $up['description']);
        $this->assertSame(0x22C55E, $up['color']);

        $down = $this->messages()->rankChange(new RankChanged($player, RankedQueue::Solo, $plat4, $gold1));
        $this->assertSame('⬇️ Turun rank', $down['title']);
        $this->assertStringStartsWith('**Ayam** turun dari **Platinum IV** ke **Gold I** di Ranked Solo/Duo (90 LP).', $down['description']);
        $this->assertSame(0xEF4444, $down['color']);
    }

    public function test_apex_tiers_have_no_division_in_the_message(): void
    {
        $player = $this->makePlayer('Alpha');
        $diamond = $this->addSnapshot($player, RankedQueue::Flex, 'DIAMOND', 'I', 99);
        $master = $this->addSnapshot($player, RankedQueue::Flex, 'MASTER', 'I', 0);

        $embed = $this->messages()->rankChange(new RankChanged($player, RankedQueue::Flex, $diamond, $master));

        $this->assertStringContainsString('**Diamond I**', $embed['description']);
        $this->assertStringContainsString('**Master**', $embed['description']);
        $this->assertStringContainsString('Ranked Flex', $embed['description']);
    }

    public function test_the_test_message(): void
    {
        $embed = $this->messages()->test();

        $this->assertSame('✅ Webhook terhubung', $embed['title']);
        $this->assertStringContainsString(config('app.name'), $embed['description']);
    }
}
