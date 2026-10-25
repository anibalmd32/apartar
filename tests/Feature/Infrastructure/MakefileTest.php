<?php

declare(strict_types=1);

function makefile(): string
{
    return (string) file_get_contents(base_path('Makefile'));
}

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

it('define las recetas de backend como scripts de composer', function (string $script): void {
    expect(composerManifest()['scripts'])->toHaveKey($script);
})->with(['lint', 'analyse', 'test', 'test:coverage', 'tenancy', 'check']);

it('define las recetas de frontend como scripts de npm', function (string $script): void {
    expect(packageManifest()['scripts'])->toHaveKey($script);
})->with(['lint', 'types', 'test', 'build', 'check']);

it('mantiene el Makefile como alias y no como segunda definición', function (): void {
    expect(makefileRecipes())
        ->toContain('composer run lint')
        ->toContain('composer run analyse')
        ->toContain('composer run test')
        ->toContain('composer run check')
        ->toContain('composer run tenancy')
        ->toContain('npm run check');

    expect(makefileRecipes())
        ->not->toContain('vendor/bin/pint')
        ->not->toContain('vendor/bin/phpstan')
        ->not->toContain('vendor/bin/pest');
});

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
