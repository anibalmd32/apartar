<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Illuminate\Support\Str;

final class NameDivergence
{
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'da', 'do', 'dos'];

    private const TYPO_TOLERANT_LENGTH = 5;

    public static function isRadical(string $before, string $after): bool
    {
        $was = self::words($before);
        $is = self::words($after);

        if ($was === [] || $is === []) {
            return false;
        }

        foreach ($was as $word) {
            foreach ($is as $candidate) {
                if (self::sameWord($word, $candidate)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $ascii = mb_strtolower(Str::ascii($name));

        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $words === false ? [] : $words,
            fn (string $word): bool => strlen($word) > 1 && ! in_array($word, self::PARTICLES, true),
        ));
    }

    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return strlen($a) >= self::TYPO_TOLERANT_LENGTH
            && strlen($b) >= self::TYPO_TOLERANT_LENGTH
            && levenshtein($a, $b) <= 1;
    }
}
