<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Events\RankChanged;
use App\Services\Discord\DiscordMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\BuildsGames;
use Tests\TestCase;

class DiscordMessagesTest extends TestCase
{
    use BuildsGames;
    use RefreshDatabase;

    private function messages(): DiscordMessages
    {
        return app(DiscordMessages::class);
    }

    public function test_a_won_match_lists_the_tracked_player_with_their_numbers(): void
    {
        $this->freezeTime();
        $player = $this->makePlayer('Alpha', ['display_name' => 'Ayam']);
        $match = $this->addGame($player, [
            'champion_name' => 'Ahri', 'team_position' => 'MIDDLE', 'win' => true,
            'kills' => 8, 'deaths' => 2, 'assists' => 10, 'total_minions_killed' => 270, 'neutral_minions_killed' => 30,
        ], startedAt: now()->subHour());

        $embed = $this->messages()->match($match);

        $this->assertSame('✅ Menang · Solo/Duo', $embed['title']);
        $this->assertSame(
            "**Ayam** · Ahri (Mid)\n**8/2/10** · 9.00 KDA · 10.0 CS/min · 300 CS\n100% kill participation · 20.0k damage\n🏅 **MVP** · skor 53/100",
            $embed['description'],
        );
        $this->assertSame(0x22C55E, $embed['color']);
        $this->assertSame('30:00 · Patch 16.1', $embed['footer']['text']);
        // The timestamp is the moment the game ended.
        $this->assertSame(now()->subHour()->addSeconds(1800)->toIso8601String(), $embed['timestamp']);
    }

    public function test_a_lost_match_is_red(): void
    {
        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player, ['win' => false], queueId: 440);

        $embed = $this->messages()->match($match);

        $this->assertSame('❌ Kalah · Flex', $embed['title']);
        $this->assertSame(0xEF4444, $embed['color']);
    }

    public function test_friends_on_the_same_team_share_one_message(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $match = $this->addTeamGame([$a, $b], [], blueWins: true);

        $embed = $this->messages()->match($match);

        $this->assertSame('✅ Menang · Solo/Duo', $embed['title']);
        $this->assertStringContainsString('**Alpha**', $embed['description']);
        $this->assertStringContainsString('**Beta**', $embed['description']);
        $this->assertStringNotContainsString('✅ **', $embed['description']);   // one shared result, no per-line marker
    }

    public function test_friends_against_each_other_get_a_result_per_player(): void
    {
        $a = $this->makePlayer('Alpha');
        $b = $this->makePlayer('Beta');
        $match = $this->addTeamGame([$a], [$b], blueWins: true);

        $embed = $this->messages()->match($match);

        $this->assertSame('⚔️ Teman melawan teman · Solo/Duo', $embed['title']);
        $this->assertSame(0x38BDF8, $embed['color']);
        $this->assertStringContainsString('✅ **Alpha**', $embed['description']);
        $this->assertStringContainsString('❌ **Beta**', $embed['description']);
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

        $this->assertStringNotContainsString('Stranger', $this->messages()->match($match)['description']);

        $match->participants()->whereNotNull('player_id')->delete();
        $this->assertNull($this->messages()->match($match->fresh()));
    }

    public function test_markdown_in_names_is_escaped(): void
    {
        $player = $this->makePlayer('Evil', ['display_name' => '*Boss*_x [link](http://a.b) @everyone']);
        $match = $this->addGame($player);

        $description = $this->messages()->match($match)['description'];

        $this->assertStringContainsString('\*Boss\*\_x \[link\]\(http://a.b\) @everyone', $description);
    }

    public function test_links_are_left_out_for_a_local_site_url(): void
    {
        foreach (['http://localhost:8000', 'http://127.0.0.1:8000', 'http://achylstic.test', 'http://pc.local'] as $url) {
            config(['app.url' => $url]);
            $player = $this->makePlayer('P'.crc32($url));
            $match = $this->addGame($player);

            $this->assertArrayNotHasKey('url', $this->messages()->match($match), $url);
            $this->assertArrayNotHasKey('url', $this->messages()->winStreak($player, 3), $url);
        }
    }

    public function test_links_point_to_the_site_when_it_is_public(): void
    {
        config(['app.url' => 'https://tracker.example.com']);
        URL::forceRootUrl('https://tracker.example.com');
        URL::forceScheme('https');

        $player = $this->makePlayer('Alpha');
        $match = $this->addGame($player);

        $this->assertSame("https://tracker.example.com/matches/{$match->match_id}", $this->messages()->match($match)['url']);
        $this->assertSame("https://tracker.example.com/players/{$player->id}", $this->messages()->winStreak($player, 3)['url']);
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
        $this->assertSame('**Ayam** naik dari **Gold I** ke **Platinum IV** di Ranked Solo/Duo (12 LP).', $up['description']);
        $this->assertSame(0x22C55E, $up['color']);

        $down = $this->messages()->rankChange(new RankChanged($player, RankedQueue::Solo, $plat4, $gold1));
        $this->assertSame('⬇️ Turun rank', $down['title']);
        $this->assertSame('**Ayam** turun dari **Platinum IV** ke **Gold I** di Ranked Solo/Duo (90 LP).', $down['description']);
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
