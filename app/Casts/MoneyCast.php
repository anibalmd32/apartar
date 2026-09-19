<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Mapea una columna `*_cents` a `Money` y de vuelta (`REQ-FX-001`).
 *
 * Es la frontera entre el `BIGINT` de la fila y el *value object*, y existe
 * para que ningún modelo devuelva un `int` suelto que alguien pueda sumar con
 * otro de otra moneda o dividir con una barra.
 *
 * ## Es de USD, y lo dice en voz alta
 *
 * RB-1 no deja margen: toda columna `*_cents` guarda **centavos de dólar**,
 * porque la deuda es siempre en USD (`ASU-FX-04`). Así que el cast no recibe
 * la moneda por parámetro —una moneda configurable sería una invitación a
 * declarar una columna en bolívares y romper la invariante desde el
 * `$casts`— y **lanza** si le piden persistir un importe que no es en USD.
 *
 * Un `Money` en VES guardado aquí produciría una fila indistinguible de una
 * correcta: el número cabe, la columna lo acepta, y el error solo aparece
 * cuando alguien suma ese saldo con otro. Los importes declarados en
 * bolívares tienen su propio sitio —`payments.declared_amount`, con su tasa
 * congelada al lado (`REQ-FX-006`)— y no pasan por aquí.
 *
 * ## `null` es `null`
 *
 * SP-6: una columna opcional como `compare_at_price_cents` vacía devuelve
 * `null`, **no** `Money(0)`. «Sin precio de comparación» y «precio de
 * comparación de cero dólares» son cosas distintas, y la segunda se pinta como
 * un descuento del 100 %.
 *
 * **No está cableado a ninguna columna todavía**, y es deliberado: cada tarea
 * que estrene un modelo con importes lo declara en su `$casts`.
 *
 * El parámetro de escritura es `mixed` y no `Money|int` a propósito. Declararlo
 * estrecho haría que PHPStan diera por imposible —y por código muerto— la
 * guarda que rechaza un `float`, y esa guarda es justamente la que tiene que
 * existir: a un atributo de Eloquent se le asigna lo que venga de un formulario
 * o de un `fill()`, donde el tipo ya no lo garantiza nadie. La frontera es
 * dinámica de verdad, así que se tipa dinámica y se comprueba en voz alta.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * Hidrata la columna como importe en dólares.
     *
     * El valor llega como `string` desde MySQL —así devuelve el driver un
     * `BIGINT`—, y se convierte a `int` y nunca a `float`: es el sitio exacto
     * por el que la coma flotante entraría al sistema sin que nadie la viera.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'La columna %s.%s trajo un float (%s) donde van céntimos enteros. `REQ-FX-001` RB-1 prohíbe la '
                .'coma flotante en el esquema y en el código: revisa el tipo de la columna.',
                $model->getTable(),
                $key,
                var_export($value, true),
            ));
        }

        return new Money((int) $value, Currency::USD);
    }

    /**
     * Persiste el importe como céntimos enteros.
     *
     * Admite un `Money` y también un `int` pelado, que es lo que escriben las
     * factories y las migraciones de datos. Lo que **no** admite es un
     * `float`: `19.99` como float no vale 19,99, y aceptarlo aquí sería dejar
     * entrar por el `set()` justo lo que el resto del diseño evita.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws CurrencyMismatchException si el importe no es en USD
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            if ($value->currency !== Currency::USD) {
                throw CurrencyMismatchException::whilePersisting(
                    $model->getTable().'.'.$key,
                    $value->currency,
                    Currency::USD,
                );
            }

            return $value->cents;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'La columna %s.%s solo admite un Money o un entero de céntimos, y recibió %s. Un float ahí es el '
            .'error de redondeo que `REQ-FX-001` RB-1 prohíbe; una cadena decimal se convierte antes con '
            .'Money::fromDecimalString().',
            $model->getTable(),
            $key,
            get_debug_type($value),
        ));
    }
}
