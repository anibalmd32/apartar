<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use RuntimeException;

/**
 * Un pago que no se puede aplicar a ese apartado (`T-058`).
 *
 * Son condiciones de negocio, no errores del programa: quien aprueba o
 * registra el pago (`T-061`, `T-062`) las traduce a un `422` con este
 * mensaje. Se comprueban **antes** de escribir nada, así que el apartado, las
 * cuotas y el comprobante quedan como estaban.
 */
final class PaymentNotApplicableException extends RuntimeException
{
    public const STATUS = 422;

    /**
     * `REQ-PAY-005` SP-2, `REQ-PAY-007` SP-4: un apartado terminal no cobra.
     */
    public static function layawayNotCollecting(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s» y ya no admite pagos.', $status->label()));
    }

    /**
     * `REQ-FX-006` SP-4: lo declarado, convertido, no llega a un centavo de
     * dólar. No se crean pagos de cero.
     */
    public static function convertsToZero(Money $settled, string $rate): self
    {
        return new self(sprintf(
            'El importe de %d céntimos en %s a una tasa de %s no llega a un centavo de dólar: no se puede conciliar un pago de cero.',
            $settled->cents,
            $settled->currency->value,
            $rate,
        ));
    }

    /**
     * Un pago que no cubre la inicial de un apartado que la espera. Activar
     * con él contaría como aplicada una inicial que no se cobró entera
     * (`REQ-PAY-005` RB-5: se activa y se aplica **el excedente sobre la
     * inicial**, así que la inicial se cubre entera o no se activa).
     */
    public static function belowDownPayment(Money $paid, Money $downPayment): self
    {
        return new self(sprintf(
            'El pago es de %d centavos y la inicial del apartado es de %d: la inicial se paga entera para activarlo.',
            $paid->cents,
            $downPayment->cents,
        ));
    }
}
