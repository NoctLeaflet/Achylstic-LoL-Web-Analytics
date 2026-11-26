<?php

namespace App\Services\Discord;

/**
 * Picks one of the jokes of config/quips.php for a Discord message.
 *
 * Categories are tried in the given order (the most notable one first). A line is
 * only usable when every {placeholder} in it has a value, so a line about LP is
 * never shown when the LP change is unknown. The choice depends on the seed, so
 * the same game always gets the same line, and a line already used in a message
 * is not repeated for the next player.
 */
final class QuipPicker
{
    /**
     * @param  list<string>  $categories
     * @param  array<string, string|int|null>  $values  placeholder name => value; null means unknown
     * @param  list<string>  $used  templates already used; the picked one is added
     */
    public function pick(array $categories, array $values, string $seed, array &$used = []): ?string
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

            $line = $candidates[crc32("{$seed}|{$category}") % count($candidates)];
            $used[] = $line;

            $replacements = [];
            foreach ($known as $name => $value) {
                $replacements['{'.$name.'}'] = (string) $value;
            }

            return strtr($line, $replacements);
        }

        return null;
    }
}
