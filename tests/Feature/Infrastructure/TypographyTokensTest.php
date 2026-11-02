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
