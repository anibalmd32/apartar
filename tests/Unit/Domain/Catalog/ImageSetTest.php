<?php

declare(strict_types=1);

use App\Domain\Catalog\ImageSet;

it('declara las tres conversiones con su anchura', function (): void {
    expect(ImageSet::CONVERSIONS)->toBe([
        'thumb' => 400,
        'card' => 800,
        'zoom' => 1600,
    ]);
});

it('fija la calidad del WebP en 82', function (): void {
    expect(ImageSet::QUALITY)->toBe(82);
});

it('compone el alt por defecto con el nombre y el número', function (): void {
    expect(ImageSet::defaultAlt('Taladro Bosch', 1))->toBe('Taladro Bosch — imagen 1');
    expect(ImageSet::defaultAlt('Taladro Bosch', 4))->toBe('Taladro Bosch — imagen 4');
});

it('da un alt distinto a cada imagen del mismo producto', function (): void {
    $alts = array_map(fn (int $number): string => ImageSet::defaultAlt('Taladro', $number), range(1, 3));

    expect($alts)->toHaveCount(3);
    expect(array_unique($alts))->toHaveCount(3);
});

it('viaja con las cuatro claves del contrato', function (): void {
    $set = new ImageSet('t.webp', 'c.webp', 'z.webp', 'Un taladro');

    expect($set->toArray())->toBe([
        'thumb' => 't.webp',
        'card' => 'c.webp',
        'zoom' => 'z.webp',
        'alt' => 'Un taladro',
    ]);
});
