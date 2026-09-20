<?php

declare(strict_types=1);

use App\Domain\Catalog\ImageSet;

/**
 * El contrato `ImageSet` del módulo [11] (`REQ-CAT-005` RB-4).
 *
 * Lo que se puede fijar sin base de datos es lo que de verdad decide esta
 * clase: **las tres conversiones y el `alt` por defecto**. La caída al
 * original cuando una conversión no existe (RB-3) necesita un medio guardado y
 * se prueba en `ProductImageTest`, contra archivos reales.
 */
it('declara las tres conversiones de REQ-CAT-005 con su anchura', function (): void {
    expect(ImageSet::CONVERSIONS)->toBe([
        'thumb' => 400,
        'card' => 800,
        'zoom' => 1600,
    ]);
});

it('fija la calidad del WebP en 82', function (): void {
    expect(ImageSet::QUALITY)->toBe(82);
});

/**
 * **RB-4 · el `alt` por defecto.** Un `alt` vacío o repetido en las diez
 * imágenes es una ficha que no se puede leer con lector de pantalla, y el
 * defecto existe para que eso no dependa de que alguien lo escriba.
 */
it('compone el alt por defecto con el nombre y el número', function (): void {
    expect(ImageSet::defaultAlt('Taladro Bosch', 1))->toBe('Taladro Bosch — imagen 1');
    expect(ImageSet::defaultAlt('Taladro Bosch', 4))->toBe('Taladro Bosch — imagen 4');
});

/**
 * Y cada imagen recibe uno **distinto**: diez «Taladro Bosch» seguidos no
 * describen nada, que es exactamente lo que el número evita.
 */
it('da un alt distinto a cada imagen del mismo producto', function (): void {
    $alts = array_map(fn (int $number): string => ImageSet::defaultAlt('Taladro', $number), range(1, 3));

    expect($alts)->toHaveCount(3);
    expect(array_unique($alts))->toHaveCount(3);
});

/**
 * La forma con la que viaja al cliente es exactamente la del contrato: cuatro
 * claves y ninguna más. Lo que el negocio ve de más —«procesando», «revisar»—
 * va por otro sitio, porque el cliente final no tiene por qué enterarse de la
 * cola.
 */
it('viaja con las cuatro claves del contrato', function (): void {
    $set = new ImageSet('t.webp', 'c.webp', 'z.webp', 'Un taladro');

    expect($set->toArray())->toBe([
        'thumb' => 't.webp',
        'card' => 'c.webp',
        'zoom' => 'z.webp',
        'alt' => 'Un taladro',
    ]);
});
