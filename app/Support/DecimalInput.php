<?php

declare(strict_types=1);

namespace App\Support;

final class DecimalInput
{
    public static function normalize(string $raw): string
    {
        $raw = (string) preg_replace('/[^\d.,]/', '', $raw);
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            return str_replace(',', '.', str_replace('.', '', $raw));
        }

        if ($lastComma !== false) {
            return str_replace(',', '', $raw);
        }

        if ($lastDot !== false && (substr_count($raw, '.') > 1 || strlen($raw) - $lastDot - 1 === 3)) {
            return str_replace('.', '', $raw);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function normalizeKeys(array $input, array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (is_string($input[$key] ?? null) && trim($input[$key]) !== '') {
                $normalized[$key] = self::normalize($input[$key]);
            }
        }

        return $normalized;
    }
}
