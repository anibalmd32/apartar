<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Catalog\CatalogSort;
use Illuminate\Pagination\Cursor;

final class CatalogCursor
{
    public const NAME = 'cursor';

    public const PER_PAGE = 12;

    public const OFFSET = 'offset';

    public const FIRST_PAGE = '';

    public const MAX_OFFSET = 100 * self::PER_PAGE;

    private const MAX_LENGTH = 2048;

    private const MAX_NAME_LENGTH = 200;

    public static function from(mixed $encoded, CatalogSort $sort): ?Cursor
    {
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_LENGTH) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parameters = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parameters)) {
            return null;
        }

        $keyset = $sort->keyset();

        return $keyset === null ? self::withOffset($parameters) : self::withShape($parameters, array_keys($keyset));
    }

    public static function offsetOf(?Cursor $cursor): int
    {
        return (int) ($cursor?->parameter(self::OFFSET) ?? 0);
    }

    public static function encodeOffset(int $offset): string
    {
        return (new Cursor([self::OFFSET => $offset]))->encode();
    }

    /**
     * @param  array<mixed>  $parameters
     * @param  list<string>  $columns
     */
    private static function withShape(array $parameters, array $columns): ?Cursor
    {
        $direction = $parameters['_pointsToNextItems'] ?? null;
        unset($parameters['_pointsToNextItems']);

        if (! is_bool($direction) || array_keys($parameters) !== $columns) {
            return null;
        }

        $values = [];

        foreach ($parameters as $column => $value) {
            $value = self::value((string) $column, $value);

            if ($value === null) {
                return null;
            }

            $values[$column] = $value;
        }

        return new Cursor($values, $direction);
    }

    /**
     * @param  array<mixed>  $parameters
     */
    private static function withOffset(array $parameters): ?Cursor
    {
        $offset = $parameters[self::OFFSET] ?? null;

        if (array_keys($parameters) !== [self::OFFSET, '_pointsToNextItems'] || $parameters['_pointsToNextItems'] !== true) {
            return null;
        }

        if (! is_int($offset) || $offset <= 0 || $offset > self::MAX_OFFSET) {
            return null;
        }

        return new Cursor([self::OFFSET => $offset]);
    }

    private static function value(string $column, mixed $value): int|string|null
    {
        return match ($column) {
            'catalog_added_at' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
                ? $value
                : null,
            'products.name' => is_string($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= self::MAX_NAME_LENGTH
                ? $value
                : null,
            default => (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value) && strlen($value) <= 18)
                ? (int) $value
                : null,
        };
    }
}
