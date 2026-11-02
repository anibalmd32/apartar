<?php

declare(strict_types=1);

use function Pest\Laravel\get;

function appStylesheet(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

it('la página precarga Plus Jakarta Sans desde el propio dominio y no depende de fonts.bunny.net', function (): void {
    get('/')
        ->assertOk()
        ->assertSee('<link rel="preload" href="/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertDontSee('fonts.bunny.net', false);
});

it('cada archivo de fuente que declara la hoja de estilos existe en public/fonts y se muestra con swap', function (): void {
    preg_match_all("~url\\('(/fonts/[^']+\\.woff2)'\\)~", appStylesheet(), $matches);

    expect($matches[1])->toContain('/fonts/plus-jakarta-sans-latin.woff2');

    foreach ($matches[1] as $path) {
        expect(public_path(ltrim($path, '/')))->toBeFile();
    }

    preg_match_all("~@font-face\\s*\\{[^}]*font-family:\\s*'Plus Jakarta Sans';[^}]*\\}~", appStylesheet(), $faces);

    expect($faces[0])->not->toBeEmpty();

    foreach ($faces[0] as $face) {
        expect($face)->toContain('font-display: swap');
    }
});

it('la letra de respaldo se ajusta a las medidas de Plus Jakarta Sans para que el texto no salte al cargar', function (): void {
    preg_match("~@font-face\\s*\\{[^}]*font-family:\\s*'Plus Jakarta Sans Fallback';[^}]*\\}~", appStylesheet(), $fallback);

    expect($fallback[0] ?? '')->toContain('size-adjust:')->toContain('ascent-override:')->toContain('descent-override:')
        ->and(appStylesheet())->toContain("'Plus Jakarta Sans', 'Plus Jakarta Sans Fallback'");
});
