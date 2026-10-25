<?php

declare(strict_types=1);

use App\Domain\Payment\IdempotencyKeyReusedException;

it('responde con un 422 y pide recargar', function (): void {
    expect(IdempotencyKeyReusedException::STATUS)->toBe(422)
        ->and((new IdempotencyKeyReusedException)->getMessage())->toContain('Recarga la página');
});
