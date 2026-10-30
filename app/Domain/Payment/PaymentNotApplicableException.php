<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use RuntimeException;

final class PaymentNotApplicableException extends RuntimeException
{
    public const STATUS = 422;

    public const CLOSED_MESSAGE = 'Este negocio cerró su cuenta en Apartar y ya no recibe comprobantes. Tu historial sigue aquí para que lo consultes.';

    public static function organizationClosed(): self
    {
        return new self(self::CLOSED_MESSAGE);
    }

    public static function layawayNotCollecting(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s» y ya no admite pagos.', $status->label()));
    }

    public static function convertsToZero(Money $settled, string $rate): self
    {
        return new self(sprintf(
            'El importe de %d céntimos en %s a una tasa de %s no llega a un centavo de dólar: no se puede conciliar un pago de cero.',
            $settled->cents,
            $settled->currency->value,
            $rate,
        ));
    }
}
