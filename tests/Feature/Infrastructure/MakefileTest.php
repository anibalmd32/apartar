<?php

declare(strict_types=1);

/**
 * Comandos de trabajo (05 §9.4) y la decisión de T-003 sobre dónde viven las
 * recetas: **una sola vez**, como scripts de `composer` y de `npm`. El
 * `Makefile` es un alias sobre ellos y CI invoca los scripts, no `make`, que
 * no está instalado en el host de desarrollo.
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

/**
 * @return array<string, mixed>
 */
function composerManifest(): array
{
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true);

    return $manifest;
}

/**
 * @return array<string, mixed>
 */
function packageManifest(): array
{
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents(base_path('package.json')), true);

    return $manifest;
}

it('declara los seis comandos de 05 §9.4', function (string $target): void {
    expect(makefile())->toMatch('/^'.preg_quote($target, '/').':/m');
})->with(['up', 'down', 'fresh', 'sh', 'test', 'check', 'tenancy']);

/**
 * `REQ-QAX-001`: los scripts de composer son la superficie esperada del
 * requisito, no el Makefile.
 */
it('define las recetas de backend como scripts de composer', function (string $script): void {
    expect(composerManifest()['scripts'])->toHaveKey($script);
})->with(['lint', 'analyse', 'test', 'test:coverage', 'tenancy', 'check']);

/**
 * `REQ-QAX-002`: lo mismo del lado del frontend.
 */
it('define las recetas de frontend como scripts de npm', function (string $script): void {
    expect(packageManifest()['scripts'])->toHaveKey($script);
})->with(['lint', 'types', 'test', 'build', 'check']);

/**
 * La regla que evita dos definiciones de la misma comprobación: el Makefile
 * delega, no reimplementa.
 */
it('mantiene el Makefile como alias y no como segunda definición', function (): void {
    expect(makefileRecipes())
        ->toContain('composer run lint')
        ->toContain('composer run analyse')
        ->toContain('composer run test')
        ->toContain('composer run check')
        ->toContain('composer run tenancy')
        ->toContain('npm run check');

    // Si una herramienta aparece por su nombre, es que la receta se ha
    // duplicado aquí en lugar de vivir en el script.
    expect(makefileRecipes())
        ->not->toContain('vendor/bin/pint')
        ->not->toContain('vendor/bin/phpstan')
        ->not->toContain('vendor/bin/pest');
});

/**
 * `REQ-INF-004` SP-4: cachear la configuración en desarrollo es el origen del
 * clásico "cambié el .env y no toma efecto". Ni `config:cache` ni `optimize`
 * pueden aparecer en ninguna receta de trabajo.
 */
it('no cachea la configuración en el flujo de desarrollo', function (): void {
    $recipes = makefileRecipes()
        .json_encode(composerManifest()['scripts'])
        .json_encode(packageManifest()['scripts']);

    expect($recipes)
        ->not->toContain('config:cache')
        ->not->toContain('route:cache')
        ->not->toContain('view:cache')
        ->not->toContain('event:cache')
        ->not->toContain('artisan optimize');
});

it('cubre las seis herramientas de check entre los dos manifiestos', function (): void {
    $scripts = json_encode(composerManifest()['scripts']).json_encode(packageManifest()['scripts']);

    expect($scripts)
        ->toContain('pint')
        ->toContain('phpstan')
        ->toContain('pest')
        ->toContain('eslint')
        ->toContain('tsc --noEmit')
        ->toContain('vitest');
});
