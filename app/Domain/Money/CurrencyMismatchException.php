<?php

declare(strict_types=1);

namespace App\Domain\Money;

use RuntimeException;

/**
 * Se intentó operar entre dos monedas distintas (`REQ-FX-001` RB-3).
 *
 * **No hay conversión implícita, y eso es la regla, no una limitación.** Un
 * `add()` entre `$20.00` y `Bs. 850,00` tiene una respuesta plausible —sumar
 * los enteros y devolver `87000`— que es basura y no lo parece. Lanzar es lo
 * que convierte ese error en algo que se ve en el primer test en lugar de en
 * un saldo que no cuadra tres hitos después.
 *
 * Quien quiera sumar importes de monedas distintas tiene que pasar por
 * `Money::convertTo()` y decir **con qué tasa**, que es precisamente el dato
 * que un `add()` silencioso se estaría inventando.
 */
final class CurrencyMismatchException extends RuntimeException
{
    /**
     * Dos importes de monedas distintas en una misma operación.
     *
     * Nombra la operación además de las dos monedas: con varias cuentas
     * encadenadas, saber que chocaron USD y VES no dice cuál de ellas lo hizo.
     */
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

    /**
     * Se intentó guardar en una columna `*_cents` un importe que no es de la
     * moneda de la columna.
     *
     * Su sitio natural es `MoneyCast::set()`: toda columna `*_cents` es de
     * centavos de **USD** (`REQ-FX-001` RB-1), así que persistir ahí un
     * `Money` en VES guardaría bolívares bajo un nombre que dice dólares —y la
     * fila resultante es indistinguible de una correcta—.
     */
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
