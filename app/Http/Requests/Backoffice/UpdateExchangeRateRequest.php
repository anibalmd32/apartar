<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Exchange\RateSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La fuente de tasa que llega del formulario (`REQ-FX-003`).
 *
 * **No valida el rango de la tasa manual**, por lo mismo que
 * `UpdateSettingsRequest`: lo valida `UpdateSettingsAction` contra
 * `SettingKey::validationRules()`, que es donde vive el rango. Dos copias del
 * `gt:0` acabarían siendo dos reglas distintas, y la que se quedara vieja
 * sería la del formulario —la que decide qué se puede escribir—.
 *
 * Lo que sí hace es quedarse con el sobre: **solo estas dos claves llegan a la
 * acción**. La ruta de la tasa no puede usarse para cambiar la ventana del
 * abono inicial de rebote, aunque alguien añada el campo al `PUT`.
 *
 * La fuente se valida contra el enum aquí porque el tipado de la acción lo
 * exige —recibe un `RateSource`, no una cadena— y un `RateSource::from()` sobre
 * texto sin comprobar lanzaría un `ValueError` en vez del `422` que le toca.
 */
final class UpdateExchangeRateRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'exchange_rate_source' => [
                'required',
                'string',
                Rule::in(array_map(fn (RateSource $source): string => $source->value, RateSource::cases())),
            ],

            // `present` y no `required`: la tasa manual **puede** ser nula
            // —lo es mientras la fuente no sea manual—, y quién la exige es la
            // regla cruzada de SP-1, que sabe qué fuente se está eligiendo.
            'exchange_rate_manual' => ['sometimes', 'nullable'],
        ];
    }

    /**
     * La fuente elegida, ya como caso del enum.
     */
    public function source(): RateSource
    {
        return RateSource::from((string) $this->validated('exchange_rate_source'));
    }

    /**
     * La tasa manual tal como se escribió, o `null` si no viene.
     *
     * Se devuelve como **cadena** y no como número: la tasa no pasa por `float`
     * en ningún tramo (`REQ-FX-001` RB-1), y este es uno de los sitios por los
     * que entraría sin que nadie lo viera. Una cadena vacía es «no la escribí»,
     * que es lo que manda un `input` numérico al borrarlo.
     */
    public function manualRate(): ?string
    {
        if (! $this->has('exchange_rate_manual')) {
            return null;
        }

        $rate = $this->validated('exchange_rate_manual');

        if ($rate === null || $rate === '') {
            return null;
        }

        return is_string($rate) ? trim($rate) : (string) $rate;
    }
}
