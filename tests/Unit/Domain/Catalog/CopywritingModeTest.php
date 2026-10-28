<?php

declare(strict_types=1);

use App\Domain\Catalog\CopywritingMode;

it('solo exige descripción para mejorarla', function (): void {
    expect(CopywritingMode::Improve->needsDescription())->toBeTrue();
    expect(CopywritingMode::Suggest->needsDescription())->toBeFalse();
});

it('viaja con los valores que manda el formulario', function (): void {
    expect(CopywritingMode::from('suggest'))->toBe(CopywritingMode::Suggest);
    expect(CopywritingMode::from('improve'))->toBe(CopywritingMode::Improve);
    expect(CopywritingMode::tryFrom('translate'))->toBeNull();
});
