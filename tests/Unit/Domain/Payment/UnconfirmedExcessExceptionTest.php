<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Payment\UnconfirmedExcessException;

/**
 * El sobrante sin confirmar (`REQ-PAY-005` SP-5): un `422` con la cifra que
 * quedaría sin asignar, en dólares y legible.
 */
it('responde con un 422 y dice cuánto sobra', function (): void {
    $exception = new UnconfirmedExcessException(new Money(1_250));

    expect(UnconfirmedExcessException::STATUS)->toBe(422)
        ->and($exception->getMessage())->toContain('$12.50')->toContain('Confírmalo')
        ->and($exception->unallocated->cents)->toBe(1_250);
});
