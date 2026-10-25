<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

enum RateSource: string
{
    case Bcv = 'bcv';

    case Parallel = 'parallel';

    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Bcv => 'BCV',
            self::Parallel => 'Paralelo',
            self::Manual => 'Manual',
        };
    }

    /**
     * @return list<self>
     */
    public static function platform(): array
    {
        $platform = [];

        foreach (self::cases() as $source) {
            if ($source !== self::Manual) {
                $platform[] = $source;
            }
        }

        return $platform;
    }

    public function isPlatformWide(): bool
    {
        return $this !== self::Manual;
    }
}
