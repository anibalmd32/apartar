<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

final class TaxonomySlug
{
    /**
     * @param  Closure(string): bool  $isTaken
     */
    public static function from(string $name, Closure $isTaken, int $maxLength = 120): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'sin-nombre';
        }

        $base = Str::limit($base, $maxLength - 4, '');
        $slug = $base;
        $suffix = 2;

        while ($isTaken($slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public static function normalize(string $slug, int $maxLength = 120): string
    {
        return Str::limit(Str::slug($slug), $maxLength, '');
    }
}
