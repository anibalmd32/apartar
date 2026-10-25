<?php

declare(strict_types=1);

namespace App\Domain\Money;

use RuntimeException;

final class CurrencyMismatchException extends RuntimeException
{
    public static function between(Currency $left, Currency $right, string $operation): self
    {
        return new self(sprintf(
            'No se puede operar (%s) entre %s y %s: no hay conversión implícita. '
            .'Pasa por Money::convertTo() indicando la tasa con la que quieres convertir.',
            $operation,
            $left->value,
            $right->value,
        ));
    }

    public static function whilePersisting(string $column, Currency $actual, Currency $expected): self
    {
        return new self(sprintf(
            'La columna %s es de centavos de %s y se intentó guardar un importe en %s. '
            .'Convierte antes con Money::convertTo() o guarda el importe en %s.',
            $column,
            $expected->value,
            $actual->value,
            $expected->value,
        ));
    }
}
