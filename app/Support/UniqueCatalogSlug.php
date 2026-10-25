<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class UniqueCatalogSlug
{
    public const MAX_LENGTH = 100;

    private const ATTEMPTS = 25;

    /**
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $save
     * @return TReturn
     *
     * @throws UniqueConstraintViolationException
     */
    public function generate(string $desired, Closure $save): mixed
    {
        $base = self::base($desired);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $save(self::candidate($base, $attempt));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private static function candidate(string $base, int $attempt): string
    {
        if ($attempt === 1) {
            return $base;
        }

        $suffix = '-'.$attempt;

        return Str::limit($base, self::MAX_LENGTH - strlen($suffix), '').$suffix;
    }

    private static function base(string $desired): string
    {
        $base = Str::slug($desired);

        return $base === '' ? 'catalogo' : Str::limit($base, self::MAX_LENGTH, '');
    }
}
