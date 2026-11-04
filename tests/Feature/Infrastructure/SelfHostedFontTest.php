<?php

declare(strict_types=1);

use function Pest\Laravel\get;

function fontStylesheet(): string
{
    return (string) file_get_contents(resource_path('css/app.css')).(string) file_get_contents(resource_path('css/pizarra.css'));
}

it('la página precarga Archivo y la letra a mano desde el propio dominio y no depende de servicios de fuentes externos', function (): void {
    get('/')
        ->assertOk()
        ->assertSee('<link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertSee('<link rel="preload" href="/fonts/gochi-hand-latin.woff2" as="font" type="font/woff2" crossorigin>', false)
        ->assertDontSee('fonts.bunny.net', false)
        ->assertDontSee('fonts.googleapis.com', false);
});

it('cada archivo de fuente que declara la hoja de estilos existe en public/fonts y se muestra con swap', function (): void {
    preg_match_all("~url\\('(/fonts/[^']+\\.woff2)'\\)~", fontStylesheet(), $matches);

    expect($matches[1])->toContain('/fonts/archivo-latin.woff2')->toContain('/fonts/gochi-hand-latin.woff2');

    foreach ($matches[1] as $path) {
        expect(public_path(ltrim($path, '/')))->toBeFile();
    }

    preg_match_all("~@font-face\\s*\\{[^}]*font-family:\\s*'(?:Archivo|Gochi Hand)';[^}]*\\}~", fontStylesheet(), $faces);

    expect($faces[0])->not->toBeEmpty();

    foreach ($faces[0] as $face) {
        expect($face)->toContain('font-display: swap');
    }
});

it('los titulares condensados tienen una letra de respaldo condensada y ajustada, para que no salten al cargar', function (): void {
    preg_match("~@font-face\\s*\\{[^}]*font-family:\\s*'Archivo Vinyl Fallback';[^}]*\\}~", fontStylesheet(), $fallback);

    expect($fallback[0] ?? '')->toContain('Arial Narrow')->toContain('size-adjust:')->toContain('ascent-override:')->toContain('descent-override:')
        ->and(fontStylesheet())->toContain("'Archivo', 'Archivo Vinyl Fallback'")
        ->and(fontStylesheet())->toContain("'Archivo', 'Archivo Fallback'");
});
