<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;

/**
 * La tasa que fija el propio negocio en sus ajustes (`REQ-FX-003` RB-3).
 *
 * ## Es la única fuente que no viene de fuera, y la única que es del tenant
 *
 * `bcv` y `parallel` son cotizaciones públicas, iguales para todo el mundo, y
 * viven en `exchange_rates`. La manual vive en
 * `organization_settings.exchange_rate_manual` de **esa** organización y
 * **nunca** llega a la tabla de plataforma (RB-4): el histórico de una tasa
 * manual es la auditoría de quien la escribió, no un dato de la plataforma.
 *
 * De ahí que este proveedor lea del contexto de tenancy en lugar de recibir la
 * organización: la interfaz de `ExchangeRateProvider` pregunta por una fuente
 * y nada más, y quien sabe de qué organización se habla es
 * `ExchangeRateService`, que lo envuelve en `runFor()`.
 *
 * ## Las dos fechas son la misma, y tiene sentido
 *
 * Para una cotización pública, `publishedAt` y `fetchedAt` son preguntas
 * distintas —cuándo la publicó el BCV y cuándo la leímos nosotros—. Aquí no
 * hay tal distancia: el negocio la escribió y en ese mismo instante quedó
 * publicada para sus catálogos. Las dos son la fecha de la última edición, y
 * eso hace que `isStale()` mida lo correcto sin ningún caso especial: una tasa
 * manual escrita hace tres semanas **está** obsoleta, y el sello del catálogo
 * tiene que decirlo.
 */
final class ManualRateProvider implements ExchangeRateProvider
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * La tasa manual de la organización activa.
     *
     * @throws ExchangeRateUnavailableException si no hay organización fijada o no hay tasa escrita
     */
    public function fetch(RateSource $source): RateSnapshot
    {
        if (! $this->supports($source)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'este proveedor solo resuelve la tasa manual del negocio',
            );
        }

        if (! $this->context->hasOrganization()) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'la tasa manual es de una organización concreta y no hay ninguna fijada',
            );
        }

        $setting = OrganizationSetting::query()
            ->where('key', SettingKey::ExchangeRateManual->value)
            ->first();

        $rate = $setting?->value;

        if (! is_string($rate) && ! is_int($rate) && ! is_float($rate)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'la organización tiene la fuente manual seleccionada y no ha escrito ninguna tasa',
            );
        }

        // `updated_at` de la fila, que es literalmente «cuándo la fijó»
        // (RB-3). Sin fila con fecha no se puede sellar el catálogo, así que
        // se cae al instante actual solo cuando el modelo aún no la tiene.
        $editedAt = $setting->updated_at !== null
            ? CarbonImmutable::parse($setting->updated_at)
            : CarbonImmutable::now();

        return new RateSnapshot(
            rate: is_string($rate) ? $rate : sprintf('%.8F', $rate),
            source: RateSource::Manual,
            publishedAt: $editedAt,
            fetchedAt: $editedAt,
        );
    }

    /**
     * Solo la manual: las públicas las trae `DolarApiProvider`.
     */
    public function supports(RateSource $source): bool
    {
        return $source === RateSource::Manual;
    }
}
