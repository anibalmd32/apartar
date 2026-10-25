<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;

/**
 * @template-covariant TSort of BackedEnum
 */
final readonly class SortWhitelist
{
    private const MAX_LENGTH = 32;

    /**
     * @param  non-empty-list<TSort>  $allowed
     */
    public function __construct(private array $allowed) {}

    /**
     * @return TSort|null
     */
    public function resolve(mixed $requested): ?BackedEnum
    {
        if (! is_string($requested) || $requested === '' || strlen($requested) > self::MAX_LENGTH) {
            return null;
        }

        foreach ($this->allowed as $sort) {
            if ($sort->value === $requested) {
                return $sort;
            }
        }

        return null;
    }
}
