<?php

declare(strict_types=1);

function cssUtility(string $name): string
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('~@utility '.preg_quote($name, '~').' \{.*?\R\}~s', $css, $match);

    return $match[0] ?? '';
}

it('en el celular los títulos se juntan -0,02em y compensan el espacio estrecho entre palabras', function (): void {
    $heading = cssUtility('tracking-heading');

    expect($heading)->toMatch('~^\s{4}letter-spacing: -0\.02em;~m')
        ->and($heading)->toMatch('~word-spacing: 0\.\d+em;~');
});

it('los títulos de menos de 24px no llevan espaciado negativo', function (): void {
    expect(cssUtility('text-h2'))->not->toBeEmpty()->not->toContain('letter-spacing');
});

it('el rótulo pequeño compartido va en minúscula normal y sin espaciado', function (): void {
    expect(cssUtility('text-label'))->not->toBeEmpty()->not->toContain('uppercase')->not->toContain('letter-spacing')
        ->and(cssUtility('text-eyebrow'))->toBeEmpty();
});

it('el título de página mide 22px en el celular sin espaciado negativo y solo se junta desde 24px', function (): void {
    $title = cssUtility('text-h1');
    $mobile = (string) preg_replace('~@media[^{]*\{[^}]*\}~s', '', $title);

    expect($mobile)->toContain('font-size: 1.375rem;')->not->toContain('letter-spacing')
        ->and($title)->toMatch('~@media \(width >= 64rem\) \{[^}]*font-size: 1\.625rem;[^}]*letter-spacing: -0\.02em;~s');
});

it('las cifras de las filas densas del panel no heredan el espaciado negativo de la cifra grande', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('~\.stat-stack > :not\(:first-child\) \.stat-card-value \{([^}]*)\}~', $css, $rule);

    expect($rule[1] ?? '')->toContain('font-size: 1.0625rem;')->toContain('letter-spacing: normal;');
});
