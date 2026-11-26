<?php

namespace App\Services\Discord;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Picks one of the jokes of config/quips.php for a Discord message.
 *
 * Categories are tried in the given order (the most notable one first). A line is
 * only usable when every {placeholder} in it has a value, so a line about LP is
 * never shown when the LP change is unknown.
 *
 * Jokes are dealt like a shuffled deck: the lines sent per category are
 * remembered (in the cache, across messages), so every line gets its turn before
 * one comes back, and the same line never comes twice in a row.
 */
final class QuipPicker
{
    /**
     * @param  list<string>  $categories
     * @param  array<string, string|int|null>  $values  placeholder name => value; null means unknown
     * @param  list<string>  $used  templates already used in this message; the picked one is added
     */
    public function pick(array $categories, array $values, array &$used = []): ?string
    {
        $known = array_filter($values, fn ($value) => $value !== null && $value !== '');

        foreach ($categories as $category) {
            $candidates = array_values(array_filter(
                (array) config("quips.{$category}", []),
                function (string $line) use ($known, $used) {
                    preg_match_all('/\{(\w+)\}/', $line, $matches);

                    return ! in_array($line, $used, true) && array_diff($matches[1], array_keys($known)) === [];
                },
            ));

            if ($candidates === []) {
                continue;
            }

            $line = $this->deal($category, $candidates);
            $used[] = $line;

            $replacements = [];
            foreach ($known as $name => $value) {
                $replacements['{'.$name.'}'] = (string) $value;
            }

            return strtr($line, $replacements);
        }

        return null;
    }

    /**
     * A random line that has not been sent since the deck of this category was
     * last shuffled.
     *
     * @param  non-empty-list<string>  $candidates
     */
    private function deal(string $category, array $candidates): string
    {
        $key = "quips.sent.{$category}";
        $sent = (array) Cache::get($key, []);
        $fresh = array_values(array_diff($candidates, $sent));

        if ($fresh === []) {
            // Every line had its turn: start over, but not with the one that was just sent.
            $others = array_values(array_diff($candidates, [end($sent)]));
            $fresh = $others === [] ? $candidates : $others;
            $sent = [];
        }

        $line = Arr::random($fresh);
        Cache::forever($key, [...$sent, $line]);

        return $line;
    }
}
