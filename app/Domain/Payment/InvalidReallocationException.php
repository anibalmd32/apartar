<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use RuntimeException;

/**
 * Un reparto nuevo que no se puede escribir (`REQ-PAY-009`).
 *
 * Son condiciones de negocio y se comprueban **antes** de tocar nada, bajo el
 * bloqueo del apartado: el pago, sus asignaciones y las cuotas quedan como
 * estaban. El controlador las devuelve al formulario con este mensaje.
 */
final class InvalidReallocationException extends RuntimeException
{
    public const STATUS = 422;

    /** RB-7 · un apartado cancelado o expirado ya no se toca. */
    public static function layawayClosed(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s»: sus pagos ya no se reasignan.', mb_strtolower($status->label())));
    }

    /** Un pago que solo pagó la inicial no tiene nada repartido entre cuotas. */
    public static function nothingAllocated(): self
    {
        return new self('Este pago no está repartido entre cuotas: no hay nada que reasignar.');
    }

    /** RB-2, SP-1 · la suma no cuadra, con la diferencia explícita. */
    public static function sumMismatch(int $current, int $proposed): self
    {
        $difference = new Money(abs($proposed - $current));

        return new self(sprintf(
            'El reparto nuevo suma %s y el pago tiene %s repartidos: %s %s. Se reasigna la aplicación del pago, no su importe.',
            MoneyFormatter::format(new Money($proposed)),
            MoneyFormatter::format(new Money($current)),
            $proposed > $current ? 'sobran' : 'faltan',
            MoneyFormatter::format($difference),
        ));
    }

    /** RB-3, SP-2 · una cuota que no es de este apartado. */
    public static function foreignInstallment(): self
    {
        return new self('Una de las cuotas no es de este apartado: un pago solo se reparte entre las cuotas de su apartado.');
    }

    /** SP-3 · lo que esa cuota admite de este pago, sin sobrepagarla. */
    public static function overpaysInstallment(int $number, int $room): self
    {
        return new self(sprintf(
            'La cuota %d admite como mucho %s de este pago: no se puede pagar una cuota de más.',
            $number,
            MoneyFormatter::format(new Money($room)),
        ));
    }

    /** El mismo reparto que ya tiene: no hay nada que cambiar ni que auditar. */
    public static function unchanged(): self
    {
        return new self('El reparto es el mismo que ya tiene el pago.');
    }

    /** Una cuota repetida o un importe que no es positivo. */
    public static function malformed(string $reason): self
    {
        return new self($reason);
    }
}
