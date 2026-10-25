<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use RuntimeException;

final class InvalidReallocationException extends RuntimeException
{
    public const STATUS = 422;

    public static function layawayClosed(LayawayStatus $status): self
    {
        return new self(sprintf('El apartado está «%s»: sus pagos ya no se reasignan.', mb_strtolower($status->label())));
    }

    public static function nothingAllocated(): self
    {
        return new self('Este pago no está repartido entre cuotas: no hay nada que reasignar.');
    }

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

    public static function foreignInstallment(): self
    {
        return new self('Una de las cuotas no es de este apartado: un pago solo se reparte entre las cuotas de su apartado.');
    }

    public static function overpaysInstallment(int $number, int $room): self
    {
        return new self(sprintf(
            'La cuota %d admite como mucho %s de este pago: no se puede pagar una cuota de más.',
            $number,
            MoneyFormatter::format(new Money($room)),
        ));
    }

    public static function unchanged(): self
    {
        return new self('El reparto es el mismo que ya tiene el pago.');
    }

    public static function malformed(string $reason): self
    {
        return new self($reason);
    }
}
