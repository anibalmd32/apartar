<?php

declare(strict_types=1);

namespace App\Support;

enum DocumentKind: string
{
    case Cedula = 'cedula';
    case Rif = 'rif';

    /**
     * @return non-empty-list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Cedula => ['V', 'E'],
            self::Rif => ['J', 'G'],
        };
    }

    public function minDigits(): int
    {
        return match ($this) {
            self::Cedula => 6,
            self::Rif => 8,
        };
    }

    public function maxDigits(): int
    {
        return match ($this) {
            self::Cedula => 9,
            self::Rif => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cedula => 'cédula',
            self::Rif => 'RIF',
        };
    }

    public static function fromPrefix(string $letter): ?self
    {
        foreach (self::cases() as $kind) {
            if (in_array($letter, $kind->prefixes(), true)) {
                return $kind;
            }
        }

        return null;
    }
}
