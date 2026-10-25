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
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException(sprintf(
                'La columna %s.%s trajo un float (%s) donde van céntimos enteros. La coma flotante '
                .'no se admite en el esquema ni en el código: revisa el tipo de la columna.',
                $model->getTable(),
                $key,
                var_export($value, true),
            ));
        }

        return new Money((int) $value, Currency::USD);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws CurrencyMismatchException
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
            .'error de redondeo que se quiere evitar; una cadena decimal se convierte antes con '
            .'Money::fromDecimalString().',
            $model->getTable(),
            $key,
            get_debug_type($value),
        ));
    }
}
