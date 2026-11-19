<?php

namespace Tests\Feature;

use App\Enums\RankedQueue;
use App\Models\GameMatch;
use App\Models\MatchParticipant;
use App\Models\Player;
use App\Models\RankSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    private function makePlayer(array $overrides = []): Player
    {
        return Player::create(array_merge([
            'game_name' => 'Hide on bush',
            'tag_line' => 'KR1',
            'puuid' => 'puuid-'.uniqid(),
            'platform' => 'sg2',
        ], $overrides));
    }

    private function makeMatch(int $duration = 1800): GameMatch
    {
        return GameMatch::create([
            'match_id' => 'SG2_'.random_int(1000000, 9999999),
            'queue_id' => 420,
            'game_version' => '16.1.1',
            'game_mode' => 'CLASSIC',
            'game_duration' => $duration,
            'game_start_at' => now()->subHour(),
        ]);
    }

    private function participantData(array $overrides = []): array
    {
        return array_merge([
            'puuid' => 'puuid-'.uniqid(),
            'riot_id_name' => 'Someone',
            'riot_id_tag' => 'SG2',
            'champion_id' => 103,
            'champion_name' => 'Ahri',
            'team_id' => 100,
            'team_position' => 'MIDDLE',
            'win' => true,
            'kills' => 8,
            'deaths' => 2,
            'assists' => 10,
            'total_minions_killed' => 200,
            'neutral_minions_killed' => 10,
            'gold_earned' => 12000,
            'total_damage_dealt_to_champions' => 25000,
            'damage_taken' => 15000,
            'vision_score' => 30,
            'wards_placed' => 12,
            'items' => [3020, 6655, 0],
            'summoner_spells' => [4, 14],
            'perks' => ['primaryStyle' => 8100],
        ], $overrides);
    }

    public function test_player_name_falls_back_to_game_name_and_exposes_riot_id(): void
    {
        $plain = $this->makePlayer();
        $nicknamed = $this->makePlayer(['display_name' => 'Ayam']);

        $this->assertSame('Hide on bush', $plain->name);
        $this->assertSame('Ayam', $nicknamed->name);
        $this->assertSame('Hide on bush#KR1', $plain->riot_id);
        $this->assertTrue($plain->fresh()->is_active);
    }

    public function test_active_scope_excludes_inactive_players(): void
    {
        $active = $this->makePlayer();
        $this->makePlayer(['is_active' => false]);

        $this->assertSame([$active->id], Player::active()->pluck('id')->all());
    }

    public function test_rank_snapshots_belong_to_a_player_and_cast_the_queue(): void
    {
        $player = $this->makePlayer();

        $player->rankSnapshots()->create([
            'queue_type' => RankedQueue::Solo,
            'tier' => 'GOLD',
            'rank' => 'II',
            'league_points' => 54,
            'wins' => 10,
            'losses' => 8,
            'captured_at' => now(),
        ]);

        $snapshot = RankSnapshot::first();

        $this->assertSame('RANKED_SOLO_5x5', $snapshot->getRawOriginal('queue_type'));
        $this->assertSame(RankedQueue::Solo, $snapshot->queue_type);
        $this->assertTrue($snapshot->player->is($player));
        $this->assertCount(1, $player->rankSnapshots);
    }

    public function test_match_has_participants_and_links_tracked_players(): void
    {
        $player = $this->makePlayer();
        $match = $this->makeMatch();

        $match->participants()->create($this->participantData(['player_id' => $player->id, 'puuid' => $player->puuid]));
        $match->participants()->create($this->participantData(['team_id' => 200, 'win' => false]));

        $this->assertCount(2, $match->participants);

        $tracked = MatchParticipant::where('player_id', $player->id)->first();
        $this->assertTrue($tracked->gameMatch->is($match));
        $this->assertTrue($tracked->player->is($player));
        $this->assertSame([3020, 6655, 0], $tracked->items);
        $this->assertTrue($tracked->win);
        $this->assertCount(1, $player->participations);

        $untracked = $match->participants()->whereNull('player_id')->first();
        $this->assertNull($untracked->player);
    }

    public function test_remake_detection_and_scope(): void
    {
        $remake = $this->makeMatch(duration: 240);
        $normal = $this->makeMatch(duration: 300);

        $this->assertTrue($remake->isRemake());
        $this->assertFalse($normal->isRemake());
        $this->assertSame([$normal->id], GameMatch::withoutRemakes()->pluck('id')->all());
    }

    public function test_ranked_queue_helpers(): void
    {
        $this->assertSame(420, RankedQueue::Solo->queueId());
        $this->assertSame(440, RankedQueue::Flex->queueId());
        $this->assertSame([420, 440], RankedQueue::queueIds());
        $this->assertSame(RankedQueue::Flex, RankedQueue::fromSlug('flex'));
        $this->assertSame(RankedQueue::Solo, RankedQueue::fromSlug('nonsense'));
        $this->assertSame(RankedQueue::Solo, RankedQueue::fromSlug(null));
    }
}
