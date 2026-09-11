<?php

declare(strict_types=1);

/**
 * Comandos de trabajo (05 §9.4) y la regla que los mantiene honestos
 * (REQ-INF-004 RB-6 y SP-4).
 */
function makefile(): string
{
    return (string) file_get_contents(base_path('Makefile'));
}

/**
 * El Makefile sin comentarios: lo que `make` llega a ejecutar. La cabecera sí
 * nombra `config:cache`, para explicar por qué no está en ningún objetivo.
 */
function makefileRecipes(): string
{
    $lines = array_filter(
        preg_split('/\R/', makefile()) ?: [],
        fn (string $line): bool => ! str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

it('declara los seis comandos de 05 §9.4', function (string $target): void {
    expect(makefile())->toMatch('/^'.preg_quote($target, '/').':/m');
})->with(['up', 'down', 'fresh', 'sh', 'test', 'check', 'tenancy']);

/**
 * REQ-INF-004 SP-4: cachear la configuración en desarrollo es el origen del
 * clásico "cambié el .env y no toma efecto". Ni `config:cache` ni `optimize`
 * pueden aparecer en el flujo de trabajo local.
 */
it('no cachea la configuración en el flujo de desarrollo', function (): void {
    expect(makefileRecipes())
        ->not->toContain('config:cache')
        ->not->toContain('route:cache')
        ->not->toContain('view:cache')
        ->not->toContain('event:cache')
        ->not->toContain('artisan optimize');
});

it('cubre las seis herramientas de make check', function (): void {
    expect(makefile())
        ->toContain('pint')
        ->toContain('phpstan')
        ->toContain('pest')
        ->toContain('lint')
        ->toContain('tsc')
        ->toContain('vitest');
});

it('apunta make tenancy a la auditoría y a su suite', function (): void {
    expect(makefile())
        ->toContain('tenancy:audit')
        ->toContain('tests/Feature/Tenancy');
});
