<?php

namespace Tests\Feature;

use App\Services\Discord\QuipPicker;
use Tests\TestCase;

class QuipPickerTest extends TestCase
{
    /** @return list<string> */
    private function draw(int $times, string $category = 'promoted'): array
    {
        // A new instance every time, like separate queue runs: the memory lives in the cache.
        return array_map(fn () => app(QuipPicker::class)->pick([$category], []), range(1, $times));
    }

    public function test_every_line_gets_its_turn_before_one_comes_back(): void
    {
        config(['quips.promoted' => ['satu', 'dua', 'tiga', 'empat']]);

        foreach (array_chunk($this->draw(40), 4) as $round) {
            sort($round);
            $this->assertSame(['dua', 'empat', 'satu', 'tiga'], $round);
        }
    }

    public function test_the_same_line_never_comes_twice_in_a_row(): void
    {
        config(['quips.promoted' => ['satu', 'dua', 'tiga']]);

        $lines = $this->draw(60);

        foreach (range(1, count($lines) - 1) as $i) {
            $this->assertNotSame($lines[$i - 1], $lines[$i]);
        }
    }

    public function test_categories_have_their_own_memory(): void
    {
        config(['quips.promoted' => ['naik'], 'quips.demoted' => ['turun']]);

        $this->assertSame(['naik', 'naik'], $this->draw(2));
        $this->assertSame(['turun'], $this->draw(1, 'demoted'));
    }

    public function test_it_fills_placeholders_and_falls_back_to_the_next_category(): void
    {
        config(['quips.promoted' => ['+{lp} LP'], 'quips.win' => ['gg {name}']]);

        $this->assertSame('gg Alpha', app(QuipPicker::class)->pick(['promoted', 'win'], ['name' => 'Alpha', 'lp' => null]));
        $this->assertNull(app(QuipPicker::class)->pick(['promoted'], ['lp' => null]));
    }
}
