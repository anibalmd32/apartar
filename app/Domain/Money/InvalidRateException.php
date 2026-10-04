<?php

declare(strict_types=1);

namespace App\Domain\Money;

use RuntimeException;

/**
 * Se intentó convertir con una tasa que no sirve (`REQ-FX-001` SP-4).
 *
 * **Antes de convertir, no después.** Una tasa `0` no produce un error de
 * `bcmul`: produce `0` —un precio de cero bolívares, que se pinta igual de
 * bien que uno correcto—, y una negativa produce un importe negativo que en un
 * sistema donde restar sí puede dar negativo (SP-3, el crédito) tampoco
 * destaca. Las dos son datos corruptos que pasan por delante de todo.
 *
 * Distinta de `ExchangeRateUnavailableException` —«la fuente no respondió»— y
 * de `MissingExchangeRateException` —«no hay ninguna tasa»—: aquí **sí** hay
 * una tasa y el problema es la cifra en sí. La ingesta de `T-020` valida la
 * plausibilidad antes de persistir (`REQ-FX-002` SP-2), así que esto es la
 * última red: lo que llega hasta la aritmética ya venía mal de otro sitio.
 */
final class InvalidRateException extends RuntimeException
{
    /**
     * Tasa cero o negativa (SP-4).
     */
    public static function notPositive(string $rate): self
    {
        return new self(sprintf(
            'La tasa "%s" no es mayor que cero y no se puede convertir con ella. '
            .'Una tasa 0 produciría un importe de 0, que no se distingue de un precio válido.',
            $rate,
        ));
    }

    /**
     * La tasa no es un decimal que `bcmul` pueda leer.
     *
     * `RateSnapshot::rate` es una cadena libre —lo es a propósito, para que
     * nunca pase por `float`—, así que nada impide que llegue aquí algo que no
     * es un número. Tiene su propio constructor porque el arreglo es distinto:
     * `notPositive()` apunta a la fuente de la tasa, esto apunta a quien la
     * parseó.
     */
    public static function malformed(string $rate): self
    {
        return new self(sprintf(
            'La tasa "%s" no es un decimal válido. Se espera una cadena numérica (nunca un float): '
            .'revisa el parser de la fuente que la produjo.',
            $rate,
        ));
    }

    /**
     * Una tasa escrita a mano con más decimales de los que se guardan
     * (`ManualRate`, `T-055`).
     *
     * No es un fallo de la fuente sino de quien la escribió, y el arreglo es
     * recortarla: la columna la redondearía en silencio y el pago diría haber
     * usado una tasa distinta de la que convirtió el importe.
     */
    public static function tooPrecise(string $rate, int $scale): self
    {
        return new self(sprintf('La tasa "%s" tiene más de %d decimales, que es lo que se guarda de ella.', $rate, $scale));
    }

    /**
     * Una tasa escrita a mano con más cifras enteras de las que caben en la
     * columna (`ManualRate`): el `INSERT` reventaría en vez de responder.
     */
    public static function tooLarge(string $rate, int $digits): self
    {
        return new self(sprintf('La tasa "%s" tiene más de %d cifras enteras, que es lo que cabe de ella.', $rate, $digits));
    }
}
