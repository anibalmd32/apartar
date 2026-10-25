<?php

declare(strict_types=1);

use App\Domain\Tenancy\OrganizationHasActiveLayawaysException;

it('nombra los apartados en curso y los conserva', function (): void {
    $exception = new OrganizationHasActiveLayawaysException(['AP-1111', 'AP-2222']);

    expect($exception->codes)->toBe(['AP-1111', 'AP-2222'])
        ->and($exception->getMessage())->toContain('AP-1111, AP-2222')
        ->and($exception->getMessage())->toContain('Complétalos o cancélalos')
        ->and($exception->getMessage())->not->toContain('más');
});

it('nombra los diez primeros y cuenta el resto', function (): void {
    $codes = array_map(fn (int $n): string => sprintf('AP-%04d', $n), range(1, 13));

    $exception = new OrganizationHasActiveLayawaysException($codes);

    expect($exception->codes)->toHaveCount(13)
        ->and($exception->getMessage())->toContain('AP-0010 y 3 más')
        ->and($exception->getMessage())->not->toContain('AP-0011');
});
