<?php

declare(strict_types=1);

use App\Domain\Layaway\ExpirationOutcome;

it('empieza en cero y cuenta expirados y retenidos por separado', function (): void {
    $outcome = (new ExpirationOutcome)->withExpired()->withExpired()->withHeld();

    expect($outcome->toArray())->toBe(['expired' => 2, 'held' => 1]);
});

it('suma las pasadas de dos organizaciones sin tocar ninguna', function (): void {
    $first = new ExpirationOutcome(expired: 3, held: 1);
    $second = new ExpirationOutcome(expired: 2);

    expect($first->plus($second)->toArray())->toBe(['expired' => 5, 'held' => 1])
        ->and($first->toArray())->toBe(['expired' => 3, 'held' => 1]);
});
