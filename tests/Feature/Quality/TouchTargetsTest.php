<?php

declare(strict_types=1);

use App\Support\SourceFiles;

const TOUCH_MEDIA_QUERY = '@media (pointer: coarse), (width < 64rem)';

function appStylesheet(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

function cssBlocksAfter(string $css, string $header, bool $inside): string
{
    $kept = '';
    $cursor = 0;

    while (($start = strpos($css, $header, $cursor)) !== false) {
        $open = (int) strpos($css, '{', $start);
        $depth = 0;
        $index = $open;

        for ($length = strlen($css); $index < $length; $index++) {
            $depth += match ($css[$index]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                break;
            }
        }

        $kept .= $inside ? substr($css, $open, $index - $open + 1)."\n" : substr($css, $cursor, $start - $cursor);
        $cursor = $index + 1;
    }

    return $inside ? $kept : $kept.substr($css, $cursor);
}

it('la variante touch también se activa en pantallas de celular aunque el puntero sea fino', function (): void {
    expect(cssBlocksAfter(appStylesheet(), '@custom-variant touch', true))->toContain(TOUCH_MEDIA_QUERY);
});

it('en el celular todos los botones miden al menos 44 px, también los pequeños', function (): void {
    $touch = cssBlocksAfter(appStylesheet(), TOUCH_MEDIA_QUERY, true);

    expect($touch)->toContain('.btn:not(.btn-link)')
        ->toMatch('/\.btn-square,\s*\.btn-circle\s*\{\s*min-width: 2\.75rem;/')
        ->not->toContain('btn-xs');
});

it('el área táctil extendida solo existe en el celular y alcanza los enlaces de texto', function (): void {
    $css = appStylesheet();

    expect(cssBlocksAfter($css, TOUCH_MEDIA_QUERY, true))
        ->toContain('.link, .btn-link, .checkbox, .toggle, .radio, .hit-area, .hit-area-children > :is(a, button))::after')
        ->toContain('height: max(100%, 2.75rem);')
        ->and(cssBlocksAfter($css, TOUCH_MEDIA_QUERY, false))->not->toContain('hit-area');
});

it('ningún componente depende solo del puntero grueso para su tamaño táctil', function (): void {
    $offenders = array_values(array_filter(
        SourceFiles::in(resource_path('js'), ['ts', 'tsx']),
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'pointer-coarse:'),
    ));

    expect($offenders)->toBe([]);
});
