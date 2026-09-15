<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Guarda los ajustes de operación de un negocio (`REQ-ORG-006`).
 *
 * Tres reglas gobiernan este archivo, y las tres son fáciles de perder de
 * vista:
 *
 *  1. **Una clave desconocida se ignora en silencio** (RB-2). No es
 *     permisividad: es lo que permite desplegar una versión que ya envía una
 *     clave nueva contra filas sembradas antes, y lo que hace que el
 *     formulario de una pestaña abierta desde ayer —con `storefront_enabled`
 *     todavía dentro— no reviente al guardarse.
 *  2. **Lo desconocido se ignora; lo conocido se valida** (SP-1). Ignorar de
 *     más convertiría un rango mal escrito en un ajuste guardado y roto, que
 *     es peor que el `422`.
 *  3. **La caché se invalida en la misma operación** (RB-3). Invalidar después
 *     dejaría a quien acaba de guardar viendo lo de antes, que es el bug que
 *     hace dudar de si el formulario funciona.
 *
 * Y una cuarta que no es de aquí pero se nota aquí: **guardar no es aplicar**
 * (RB-4). Ninguna clave toca nada ya creado; lo que cada una alcanza lo
 * declara `SettingKey::scope()` y la pantalla lo enseña **antes** de guardar.
 */
final class UpdateSettingsAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $values  claves crudas, tal como llegan del formulario
     * @param  User  $actor  quien guarda
     * @return array<string, mixed> los ajustes ya resueltos de la organización
     *
     * @throws AuthorizationException si el actor no gobierna los ajustes (SP-2)
     * @throws ValidationException si algún valor conocido está fuera de rango (SP-1)
     */
    public function handle(array $values, User $actor): array
    {
        $organization = $this->context->organization();

        Gate::forUser($actor)->authorize('updateSettings', $organization);

        $known = $this->onlyKnownKeys($values);

        if ($known === []) {
            return $this->settings->all();
        }

        $validated = $this->validate($known);

        DB::transaction(function () use ($validated, $actor): void {
            foreach ($validated as $key => $value) {
                $setting = SettingKey::fromKey((string) $key);

                OrganizationSetting::query()->updateOrCreate(
                    ['key' => $setting],
                    ['value' => $value, 'updated_by' => $actor->getKey()],
                );
            }
        });

        // RB-3 · invalidar **después del commit** y no dentro: si la
        // transacción se deshiciera, la caché borrada se repoblaría con lo que
        // sigue en la base, pero borrarla antes de saber si el `INSERT`
        // cuajó abre una ventana en la que otra petición la repuebla con lo
        // viejo y la escritura acaba invisible.
        $this->settings->forget($organization);

        return $this->settings->all();
    }

    /**
     * RB-2 · se queda solo con lo que el enum reconoce.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyKnownKeys(array $values): array
    {
        $known = [];

        foreach ($values as $key => $value) {
            if (SettingKey::tryFromKey((string) $key) instanceof SettingKey) {
                $known[(string) $key] = $value;
            }
        }

        return $known;
    }

    /**
     * Valida cada clave contra su propio rango, y el par de la tasa manual.
     *
     * Las reglas salen de `SettingKey` y no se escriben aquí: las usan también
     * el `FormRequest`, un seeder o un comando, y tres copias del rango de
     * `default_frequency_days` acabarían siendo tres rangos distintos.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $values): array
    {
        $rules = [];

        foreach (array_keys($values) as $key) {
            $setting = SettingKey::fromKey((string) $key);

            $rules[$setting->value] = $setting->validationRules();

            $items = $setting->itemValidationRules();

            if ($items !== null) {
                $rules[$setting->value.'.*'] = $items;
            }
        }

        $validator = Validator::make($values, $rules);

        // SP-1 de `REQ-FX-003`: elegir la fuente manual **exige** la tasa en la
        // misma operación. Va como regla cruzada y no en las de la clave
        // porque ninguna de las dos se puede juzgar sola: la tasa puede ser
        // nula, salvo cuando la fuente dice que no.
        $validator->after(function ($validator) use ($values): void {
            $this->assertManualSourceCarriesItsRate($validator, $values);
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array<string, mixed>  $values
     */
    private function assertManualSourceCarriesItsRate($validator, array $values): void
    {
        $sourceKey = SettingKey::ExchangeRateSource->value;
        $rateKey = SettingKey::ExchangeRateManual->value;

        if (($values[$sourceKey] ?? null) !== SettingKey::MANUAL_EXCHANGE_RATE_SOURCE) {
            return;
        }

        // La tasa vigente vale: quien ya la tenía fijada no está obligado a
        // reescribirla para volver a elegir «manual».
        $rate = $values[$rateKey] ?? $this->settings->get(SettingKey::ExchangeRateManual);

        if ($rate === null || $rate === '') {
            $validator->errors()->add(
                $rateKey,
                'Para usar una tasa manual tienes que escribirla en esta misma operación.',
            );
        }
    }
}
