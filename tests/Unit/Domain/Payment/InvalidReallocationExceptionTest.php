<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\InvalidReallocationException;

it('responde con un 422', function (): void {
    expect(InvalidReallocationException::STATUS)->toBe(422);
});

it('dice cuánto falta o sobra', function (): void {
    expect(InvalidReallocationException::sumMismatch(6_000, 5_000)->getMessage())->toBe(
        'El reparto nuevo suma $50,00 y el pago tiene $60,00 repartidos: faltan $10,00. Se reasigna la aplicación del pago, no su importe.',
    )->and(InvalidReallocationException::sumMismatch(6_000, 6_500)->getMessage())->toContain('sobran $5,00');
});

it('dice cuánto admite la cuota y por qué el apartado no se toca', function (): void {
    expect(InvalidReallocationException::overpaysInstallment(2, 1_500)->getMessage())->toBe('La cuota 2 admite como mucho $15,00 de este pago: no se puede pagar una cuota de más.')
        ->and(InvalidReallocationException::layawayClosed(LayawayStatus::Expired)->getMessage())->toBe(sprintf('El apartado está «%s»: sus pagos ya no se reasignan.', mb_strtolower(LayawayStatus::Expired->label())));
});
