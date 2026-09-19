<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Trae la tasa de las fuentes públicas y la persiste (`REQ-FX-002`).
 *
 * ## Una ejecución para toda la plataforma
 *
 * RB-0 es la regla que ordena todo este archivo: **la ingesta es de plataforma
 * y única** (`D-6`). Este job no recibe ninguna organización, no las recorre y
 * no usa ninguno de los dos *traits* de tenancy —ni `RunsInTenantContext` ni
 * `RunsForEveryOrganization`—, y eso es deliberado: la tasa del BCV es la
 * misma para todo el mundo, así que escalar a 10.000 negocios no puede
 * multiplicar las llamadas a la API externa. Corre **sin organización
 * fijada**, y hay un test que lo sostiene.
 *
 * Y más fino todavía: las dos fuentes salen de **una sola petición**, porque
 * `GET /v1/dolares` las trae juntas y `DolarApiProvider` memoriza la respuesta.
 *
 * ## Tres capas de desconfianza, y no son la misma
 *
 *  1. **No respondió** (SP-1, SP-3): reintentos con backoff 10/60/300 y
 *     timeout de 8 s. Al agotarlos se registra el error y **se conserva** lo
 *     que hubiera. Nunca se borra lo anterior por no haber podido leer lo
 *     nuevo.
 *  2. **Respondió otra cosa** (SP-4): el parser lanza y se trata igual que lo
 *     anterior. El fixture de contrato es lo que convierte un cambio silencioso
 *     del proveedor en un job rojo en vez de en una tasa `null` en producción.
 *  3. **Respondió bien, con una cifra imposible** (SP-2): `0`, negativa, o con
 *     una variación mayor al 30 % respecto a la última de esa fuente. Esta
 *     capa es la que más se olvida y la que más daño evita —una tasa corrupta
 *     no rompe nada visiblemente: **reprecia el catálogo entero de todos los
 *     negocios** y genera apartados con cifras que nadie va a poder
 *     explicar—. Y **no se reintenta**: volver a pedir la misma respuesta
 *     absurda da la misma respuesta absurda, así que se registra la alerta, se
 *     conserva la anterior y el job termina bien.
 */
class FetchExchangeRate implements ShouldQueue
{
    use Queueable;

    /**
     * Las fuentes **públicas**. La manual no se ingesta: es de cada negocio y
     * vive en sus ajustes (`REQ-FX-003` RB-4).
     *
     * @var list<RateSource>
     */
    private const PLATFORM_SOURCES = [RateSource::Bcv, RateSource::Parallel];

    /**
     * Variación máxima admisible respecto a la última tasa de esa fuente
     * (SP-2), en tanto por uno.
     */
    private const MAX_VARIATION = '0.30';

    /**
     * Escala de la comparación, la misma de `exchange_rates.rate`.
     */
    private const SCALE = 8;

    /**
     * Tres intentos (SP-1).
     */
    public int $tries = 3;

    /**
     * Backoff de 10 s, 60 s y 300 s entre intentos (SP-1).
     *
     * Creciente y no fijo porque los dos fallos que se reintentan son de otro:
     * una caída de segundos se recupera en el primer reintento, y una de
     * minutos no se arregla insistiendo cada diez segundos.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->onQueue('high');
    }

    /**
     * Impide que dos ejecuciones se pisen (SP-5).
     *
     * Pasa más de lo que parece: el scheduler despacha a las :00 y a las :30 y
     * un reintento con backoff de 300 s puede caer justo encima. Sin el
     * bloqueo, las dos vueltas leerían el mismo histórico para comparar la
     * variación y podrían insertar la misma fila dos veces.
     *
     * `dontRelease()` porque este job vuelve solo en media hora: reencolarlo
     * para que espere su turno solo añadiría una ejecución redundante a una
     * cola que es la de mayor prioridad.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('exchange-rate-ingest'))->dontRelease()->expireAfter(120)];
    }

    /**
     * @throws ExchangeRateUnavailableException si alguna fuente no se pudo leer
     */
    public function handle(ExchangeRateProvider $provider, ExchangeRateService $rates): void
    {
        $failures = [];

        foreach (self::PLATFORM_SOURCES as $source) {
            try {
                $this->ingest($provider->fetch($source), $rates);
            } catch (ExchangeRateUnavailableException $exception) {
                // Se anota y se sigue: si el payload trae oficial y no
                // paralelo, persistir la que sí vino es mejor que perder las
                // dos. El fallo se relanza al final para que el job reintente.
                $failures[$source->value] = $exception;
            }
        }

        if ($failures !== []) {
            Log::error('No se pudo leer la tasa de alguna fuente; se conserva la anterior.', [
                'sources' => array_keys($failures),
                'attempt' => $this->attempts(),
            ]);

            throw reset($failures);
        }
    }

    /**
     * Valida, deduplica, persiste y refresca la caché de una cotización.
     */
    private function ingest(RateSnapshot $snapshot, ExchangeRateService $rates): void
    {
        $previous = $this->lastPersisted($snapshot->source);

        if (! $this->isPlausible($snapshot, $previous)) {
            return;
        }

        if (! $this->isNew($snapshot, $previous)) {
            // RB-3: misma tasa **y** mismo `published_at`, así que no hay fila
            // nueva que escribir. La caché sí se refresca: que la cifra no
            // haya cambiado no es motivo para dejar que la entrada caduque y
            // mandar a todo el mundo a la base de datos.
            $rates->remember($snapshot);

            return;
        }

        $rates->remember($this->persist($snapshot));
    }

    /**
     * Inserta la fila y devuelve la cotización **tal como quedó guardada**.
     *
     * `fetched_at` no se envía: lo escribe la base de datos con su
     * `useCurrent()` (SP-6), porque un reloj de contenedor desfasado
     * falsearía el diagnóstico de la ingesta. De ahí el `refresh()`: la marca
     * que vale es la que puso MySQL, no la que creía el proceso PHP.
     *
     * La tabla es un **histórico inmutable**: solo `INSERT` (RB-2). Nunca se
     * actualiza ni se borra una fila, porque `REQ-FX-006` congela referencias
     * a estas filas en operaciones ya conciliadas.
     */
    private function persist(RateSnapshot $snapshot): RateSnapshot
    {
        $row = ExchangeRate::query()->create([
            'source' => $snapshot->source,
            'rate' => $snapshot->rate,
            'published_at' => $snapshot->publishedAt,
        ]);

        $row->refresh();

        return new RateSnapshot(
            rate: $row->rate,
            source: $row->source,
            publishedAt: $row->published_at,
            fetchedAt: $row->fetched_at,
        );
    }

    /**
     * SP-2: una cifra que no se puede creer se rechaza y se conserva la
     * anterior.
     *
     * **Sin histórico no hay contra qué comparar**, así que la primera tasa de
     * una fuente solo tiene que ser positiva. Exigirle además una variación
     * pequeña sería exigirle parecerse a algo que no existe, y dejaría una
     * instalación nueva sin poder ingestar nunca.
     */
    private function isPlausible(RateSnapshot $snapshot, ?ExchangeRate $previous): bool
    {
        if (bccomp($snapshot->rate, '0', self::SCALE) <= 0) {
            Log::error('Tasa rechazada por no ser positiva; se conserva la anterior.', [
                'source' => $snapshot->source->value,
                'rate' => $snapshot->rate,
            ]);

            return false;
        }

        if ($previous === null) {
            return true;
        }

        $variation = $this->variationBetween($previous->rate, $snapshot->rate);

        if ($variation === null || bccomp($variation, self::MAX_VARIATION, self::SCALE) <= 0) {
            return true;
        }

        Log::error('Tasa rechazada por variación implausible; se conserva la anterior.', [
            'source' => $snapshot->source->value,
            'rate' => $snapshot->rate,
            'previous' => $previous->rate,
            'variation' => $variation,
            'max' => self::MAX_VARIATION,
        ]);

        return false;
    }

    /**
     * Variación relativa entre dos tasas, en tanto por uno.
     *
     * Toda la cuenta va por `bcmath` sobre cadenas: la tasa nunca pasa por
     * `float` (`REQ-FX-001` RB-1), y menos aún en la comprobación que decide
     * si se acepta. Con una división en coma flotante, el umbral del 30 %
     * dejaría de ser exactamente el 30 % en los bordes.
     *
     * Devuelve `null` si la referencia es cero: no hay variación relativa
     * contra cero, y esa fila no debería existir porque la validación de
     * positividad la habría rechazado antes de persistirla.
     */
    private function variationBetween(string $previous, string $current): ?string
    {
        if (bccomp($previous, '0', self::SCALE) === 0) {
            return null;
        }

        $difference = bcsub($current, $previous, self::SCALE);

        if (bccomp($difference, '0', self::SCALE) < 0) {
            $difference = bcmul($difference, '-1', self::SCALE);
        }

        return bcdiv($difference, $previous, self::SCALE);
    }

    /**
     * RB-3, y **ojo con la conjunción**: hace falta que cambie la tasa **o**
     * la fecha de publicación.
     *
     * Una tasa idéntica con `published_at` nuevo **sí** es una fila nueva,
     * porque el BCV republicó: el histórico tiene que poder demostrar que ese
     * día la tasa seguía siendo esa, y no solo que lo era una semana antes.
     */
    private function isNew(RateSnapshot $snapshot, ?ExchangeRate $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        return bccomp($snapshot->rate, $previous->rate, self::SCALE) !== 0
            || ! $previous->published_at->equalTo($snapshot->publishedAt);
    }

    /**
     * La última fila de esa fuente, que es contra la que se compara todo.
     */
    private function lastPersisted(RateSource $source): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('source', $source->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }
}
