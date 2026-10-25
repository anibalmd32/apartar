<?php

declare(strict_types=1);

use App\Domain\Subscription\DunningVerdict;

it('declara los cuatro veredictos del impago', function (): void {
    expect(array_map(fn (DunningVerdict $verdict): string => $verdict->value, DunningVerdict::cases()))
        ->toBe(['settled', 'past_due', 'suspended', 'untouched']);
});
