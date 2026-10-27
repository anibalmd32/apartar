<?php

declare(strict_types=1);

use App\Domain\Catalog\NewArrival;
use Carbon\CarbonImmutable;

it('entra al catálogo cuando ya está en él y publicado, lo último de las dos cosas', function (): void {
    $published = CarbonImmutable::parse('2026-01-01');
    $added = CarbonImmutable::parse('2026-10-20');

    expect(NewArrival::enteredAt($published, $added)?->toDateString())->toBe('2026-10-20')
        ->and(NewArrival::enteredAt($added, $published)?->toDateString())->toBe('2026-10-20');
});

it('sin publicar o sin estar en el catálogo, no ha entrado', function (): void {
    expect(NewArrival::enteredAt(null, CarbonImmutable::now()))->toBeNull()
        ->and(NewArrival::enteredAt(CarbonImmutable::now(), null))->toBeNull();
});

it('es nuevo durante 14 días desde que entró', function (): void {
    $now = CarbonImmutable::parse('2026-10-27 12:00');

    expect(NewArrival::isNew($now->subDays(14), $now))->toBeTrue()
        ->and(NewArrival::isNew($now->subDays(14)->subMinute(), $now))->toBeFalse()
        ->and(NewArrival::isNew($now->addMinute(), $now))->toBeFalse()
        ->and(NewArrival::isNew(null, $now))->toBeFalse();
});
