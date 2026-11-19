<?php

namespace Tests\Feature;

use App\Models\Player;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_seed_a_database_that_is_not_a_demo_database(): void
    {
        $this->seed(DemoDataSeeder::class);

        // The test database is ":memory:", which does not end in "_demo".
        $this->assertSame(0, Player::count());
    }

    public function test_it_seeds_players_ranks_and_matches_into_a_demo_database(): void
    {
        config(['database.connections.sqlite.database' => ':memory:_demo']);

        // Same in-memory connection, only the configured name changes for the guard.
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(5, Player::count());
        $this->assertGreaterThan(0, Player::first()->rankSnapshots()->count());
        $this->assertGreaterThan(0, Player::first()->participations()->count());
    }
}
