<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class ReservedSlugs
{
    /**
     * @var list<string>
     */
    private const APPLICATION = [
        'registro',
        'precios',
        'backoffice',
        'plataforma',
        'c',
        'mi-cuenta',
        'api',
        'archivos',
        'terminos',
        'privacidad',
        'invitacion',
        'cuenta',
    ];

    /**
     * @var list<string>
     */
    private const FRAMEWORK = [
        'up',
        'storage',
    ];

    public static function contains(string $slug): bool
    {
        return in_array(Str::slug($slug), self::all(), true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::APPLICATION, ...self::FRAMEWORK];
    }

    public static function routePattern(): string
    {
        $reserved = implode('|', array_map(fn (string $slug): string => preg_quote($slug, '#'), self::all()));

        return '(?!(?:'.$reserved.')$)[a-z0-9]+(?:-[a-z0-9]+)*';
    }
}
