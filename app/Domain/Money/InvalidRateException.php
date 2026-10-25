<?php

declare(strict_types=1);

namespace App\Domain\Money;

use RuntimeException;

final class InvalidRateException extends RuntimeException
{
    public static function notPositive(string $rate): self
    {
        return new self(sprintf(
            'La tasa "%s" no es mayor que cero y no se puede convertir con ella. '
            .'Una tasa 0 produciría un importe de 0, que no se distingue de un precio válido.',
            $rate,
        ));
    }

    public static function malformed(string $rate): self
    {
        return new self(sprintf(
            'La tasa "%s" no es un decimal válido. Se espera una cadena numérica (nunca un float): '
            .'revisa el parser de la fuente que la produjo.',
            $rate,
        ));
    }

    public static function tooPrecise(string $rate, int $scale): self
    {
        return new self(sprintf('La tasa "%s" tiene más de %d decimales, que es lo que se guarda de ella.', $rate, $scale));
    }

    public static function tooLarge(string $rate, int $digits): self
    {
        return new self(sprintf('La tasa "%s" tiene más de %d cifras enteras, que es lo que cabe de ella.', $rate, $digits));
    }
}
