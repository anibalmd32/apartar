<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Cambia la fuente de la tasa de un negocio, y su tasa manual si la usa
 * (`REQ-FX-003` RB-1).
 *
 * **La elección de fuente es de cada organización** (`D-6`, `ASU-FX-02`): la
 * ingesta sigue siendo de plataforma y única, y esto solo gobierna qué fuente
 * se lee. Dos negocios pueden mostrar el mismo producto proyectado con tasas
 * distintas, cada uno con su sello.
 *
 * ## Por qué esto delega en `UpdateSettingsAction` en lugar de escribir
 *
 * La fuente y la tasa manual **son ajustes**: dos claves de `SettingKey`, en
 * la misma tabla, con la misma capacidad de `REQ-ORG-003` RB-1 —`Settings`— y
 * la misma policy. Lo que las separa en una ruta propia es la **operación**,
 * no el permiso: son un par que solo tiene sentido guardado junto, y su
 * pantalla necesita datos que el resto de los ajustes no.
 *
 * Escribir aquí un segundo camino de persistencia habría duplicado la
 * transacción, la auditoría y la invalidación de caché, y dos caminos que
 * resuelven la misma pregunta divergen: el día que uno cambie, el síntoma
 * sería una tasa guardada sin entrada de auditoría o una caché que no se
 * limpia. Lo que esta acción aporta es lo que de verdad es suyo —acotar el
 * payload a las dos claves y decidir cuándo la manual viaja—; lo demás lo hace
 * quien ya lo hacía.
 *
 * ## Cambiar de fuente no recalcula nada
 *
 * SP-7, y conviene decirlo aquí porque es donde daría la tentación: los pagos
 * ya conciliados conservan su *snapshot* (`REQ-FX-006` RB-3) y las cuotas
 * están en USD (`ASU-FX-04`). Esta acción cambia **cómo se proyecta de ahora
 * en adelante**, y no toca ni un apartado.
 */
final class UpdateExchangeRateSettingsAction
{
    public function __construct(private readonly UpdateSettingsAction $settings) {}

    /**
     * Guarda la fuente elegida y, cuando toca, la tasa manual.
     *
     * @return array<string, mixed> los ajustes ya resueltos de la organización
     *
     * @throws AuthorizationException si el actor no gobierna los ajustes (SP-3)
     * @throws ValidationException si falta la tasa manual o está fuera de rango (SP-1, SP-2)
     */
    public function handle(RateSource $source, ?string $manualRate, User $actor): array
    {
        return $this->settings->handle($this->payloadFor($source, $manualRate), $actor);
    }

    /**
     * Las claves que viajan, que no siempre son las dos.
     *
     * ## Con `manual`, las dos juntas y en la misma operación
     *
     * SP-1 lo exige, y no es una validación de formulario: es la única forma
     * de que no exista el estado intermedio «fuente manual, tasa nula». En ese
     * estado `current()` devuelve `null` y **todos los catálogos de ese negocio
     * se quedan sin bolívares** hasta que alguien vuelva a la pantalla. Un
     * estado que la spec no admite no debe poder existir ni un instante, ni
     * siquiera dentro de una transacción, así que las dos claves entran en la
     * misma llamada y `UpdateSettingsAction` las valida juntas antes de abrir
     * la suya.
     *
     * ## Sin `manual`, la tasa guardada **no se toca**
     *
     * SP-6: una organización que pasa a BCV conserva su tasa manual en los
     * ajustes, y la recupera tal cual si vuelve a elegirla. Por eso la clave
     * se omite en lugar de enviarse a `null`: mandar `null` la borraría, y
     * volver a «manual» dentro de un mes obligaría a escribirla otra vez sin
     * que nadie hubiera pedido borrarla.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(RateSource $source, ?string $manualRate): array
    {
        $payload = [SettingKey::ExchangeRateSource->value => $source->value];

        if ($source === RateSource::Manual || $manualRate !== null) {
            $payload[SettingKey::ExchangeRateManual->value] = $manualRate;
        }

        return $payload;
    }
}
