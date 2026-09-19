<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Una tasa BCV inicial, para que una instalación nueva no arranque sin ninguna
 * ([05 §6.4], `REQ-FX-005` SP-1).
 *
 * Sin esto, el hueco es real aunque dure poco: entre `migrate --seed` y la
 * primera vuelta del job pasan hasta treinta minutos, y en ese rato el
 * catálogo funcionaría en dólares puros —sin monto en bolívares y con el
 * toggle desactivado—, que es justo el estado degradado que `REQ-FX-005`
 * describe para cuando algo va mal, no para el primer día.
 *
 * ## Es un marcador de posición, y hay que saberlo
 *
 * La cifra no pretende ser la tasa de hoy: es la del payload de ejemplo de
 * [05 §6.1], y la primera ingesta la sustituye. Se siembra con `published_at`
 * en el pasado precisamente para que **se vea vieja**: `isStale()` la marca
 * desde el primer momento y el catálogo la muestra con su aviso de
 * desactualización, en lugar de aparentar una tasa fresca que nadie ha
 * consultado.
 *
 * **Y hay un efecto que conviene tener presente:** la validación de
 * plausibilidad de `FetchExchangeRate` rechaza toda variación mayor al 30 %
 * respecto a la última fila (`REQ-FX-002` SP-2). Si esta semilla quedara muy
 * lejos de la tasa real el día de la instalación, la primera ingesta se
 * rechazaría y quedaría registrada como alerta. No se resuelve bajando la
 * guarda —que es la que protege de una tasa corrupta del proveedor— sino
 * actualizando esta cifra cuando se despliegue de verdad; el log dice
 * exactamente qué pasó y con qué números.
 *
 * **Idempotente**: si ya hay alguna fila de BCV, no siembra. `exchange_rates`
 * es un histórico de solo `INSERT` (RB-2), así que sembrar dos veces añadiría
 * ruido a un historial que existe para poder justificar pagos.
 */
class ExchangeRateSeeder extends Seeder
{
    /**
     * La cifra del payload de ejemplo de [05 §6.1].
     */
    public const INITIAL_RATE = '36.50000000';

    /**
     * Cuántos días atrás se fecha, para que nazca marcada como obsoleta.
     */
    public const PUBLISHED_DAYS_AGO = 2;

    public function run(): void
    {
        if (ExchangeRate::query()->where('source', RateSource::Bcv->value)->exists()) {
            return;
        }

        ExchangeRate::query()->create([
            'source' => RateSource::Bcv,
            'rate' => self::INITIAL_RATE,
            'published_at' => CarbonImmutable::now()->subDays(self::PUBLISHED_DAYS_AGO),
        ]);
    }
}
