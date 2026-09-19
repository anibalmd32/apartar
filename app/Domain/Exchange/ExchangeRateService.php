<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * La tasa vigente, resuelta por caché, base de datos o nada
 * (`REQ-FX-002` RB-4).
 *
 * Es el único sitio desde el que el resto del sistema pregunta «¿a cuánto está
 * el dólar?». Nadie más lee `exchange_rates` ni la caché de tasas, y nadie más
 * decide qué fuente usa una organización.
 *
 * ## Los tres caminos, en orden
 *
 *  1. **Caché** `rate:{source}`, con TTL de 35 minutos —ligeramente mayor que
 *     el intervalo del job (RB-1), para que un fallo aislado de la ingesta no
 *     vacíe la caché justo cuando la fuente está caída—.
 *  2. **La última fila** de esa `source` en `exchange_rates`, por
 *     `published_at`. Es el camino que sostiene `REQ-FX-005`: con la API caída
 *     tres horas, los catálogos siguen proyectando con lo último persistido.
 *  3. **`null`**, que significa «no hay ninguna tasa» y no «cero». Quien lo
 *     recibe oculta el monto en bolívares (`REQ-FX-005` RB-4); quien necesita
 *     una para conciliar lanza `MissingExchangeRateException`.
 *
 * ## Redis caído se lee de la base de datos
 *
 * Toda operación de caché va envuelta, igual que en {@see Settings} y por el
 * mismo motivo: la caché es un atajo, nunca un requisito para responder. Si el
 * almacén lanza, se sigue contra la base de datos y quien llamó no se entera.
 * La alternativa sería que una caída de Redis dejara sin precios en bolívares a
 * todos los catálogos de la plataforma a la vez.
 *
 * ## Este servicio no llama nunca al proveedor externo
 *
 * Ni siquiera cuando no encuentra nada (`REQ-FX-005` SP-4). Quien habla con
 * DolarApi es el job, cada treinta minutos, en la cola `high`; si una petición
 * web pudiera disparar esa llamada, el tiempo de respuesta del catálogo
 * pasaría a depender de un servicio de terceros. La única excepción es la
 * fuente **manual**, que no es externa: es una fila de ajustes de la propia
 * organización.
 */
final class ExchangeRateService
{
    /**
     * Prefijo de la clave de caché, tal como lo fija RB-1.
     */
    public const CACHE_PREFIX = 'rate:';

    /**
     * 35 minutos: cinco más que el intervalo del job (RB-1).
     */
    public const TTL_SECONDS = 2_100;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly ManualRateProvider $manual,
    ) {}

    /**
     * Tasa vigente para esa organización, con **su** fuente configurada.
     *
     * Sin organización devuelve la de la fuente por defecto de la plataforma,
     * que es la que usa cualquier superficie sin negocio detrás.
     */
    public function current(?Organization $organization = null): ?RateSnapshot
    {
        if ($organization === null) {
            return $this->forSource($this->platformSource());
        }

        $source = $this->sourceFor($organization);

        if ($source === RateSource::Manual) {
            return $this->manualFor($organization);
        }

        return $this->forSource($source);
    }

    /**
     * Tasa vigente de una fuente **pública**, sin pasar por ninguna
     * organización.
     *
     * Es lo que sirve el endpoint de `REQ-FX-002` RB-6: recibe la fuente como
     * parámetro y no necesita saber —ni puede revelar— qué negocio usa cuál.
     */
    public function forSource(RateSource $source): ?RateSnapshot
    {
        $cached = $this->withoutFailing(fn (): mixed => $this->cache->get(self::cacheKeyFor($source)));

        if (is_array($cached)) {
            $snapshot = $this->snapshotFromCache($cached);

            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        $snapshot = $this->latestPersisted($source);

        if ($snapshot !== null) {
            $this->remember($snapshot);
        }

        return $snapshot;
    }

    /**
     * Qué fuente usa esa organización (`REQ-FX-003` RB-1).
     *
     * El valor sale de sus ajustes y se valida contra el enum: una fila con
     * una fuente que el código ya no conoce cae a la de plataforma en lugar de
     * lanzar, porque una tasa es un dato de presentación y no puede tumbar el
     * catálogo entero.
     */
    public function sourceFor(Organization $organization): RateSource
    {
        $configured = $this->settings->forOrganization($organization)[SettingKey::ExchangeRateSource->value] ?? null;

        return is_string($configured)
            ? RateSource::tryFrom($configured) ?? $this->platformSource()
            : $this->platformSource();
    }

    /**
     * Guarda en caché la tasa recién ingestada (RB-1).
     *
     * La escribe el job en cada vuelta, **también cuando no inserta fila**
     * (RB-3): que la cifra no haya cambiado no es motivo para dejar que la
     * entrada caduque.
     */
    public function remember(RateSnapshot $snapshot): void
    {
        $this->withoutFailing(fn () => $this->cache->put(
            self::cacheKeyFor($snapshot->source),
            [
                'rate' => $snapshot->rate,
                'source' => $snapshot->source->value,
                'published_at' => $snapshot->publishedAt->toIso8601String(),
                'fetched_at' => $snapshot->fetchedAt->toIso8601String(),
            ],
            self::TTL_SECONDS,
        ));
    }

    /**
     * Clave de caché de una fuente (RB-1).
     */
    public static function cacheKeyFor(RateSource $source): string
    {
        return self::CACHE_PREFIX.$source->value;
    }

    /**
     * Fuente por defecto de la plataforma.
     *
     * Sale del mismo sitio que el default del ajuste de cada organización, y
     * no de una constante aparte: dos censos del valor inicial divergirían el
     * día que alguien cambie uno.
     */
    private function platformSource(): RateSource
    {
        $default = SettingKey::ExchangeRateSource->defaultValue();

        return is_string($default) ? RateSource::tryFrom($default) ?? RateSource::Bcv : RateSource::Bcv;
    }

    /**
     * La tasa manual de esa organización, leída dentro de su contexto.
     *
     * `runFor()` y no un `where('organization_id', …)` a mano:
     * `organization_settings` es una tabla del tenant y escribir su filtro
     * aquí sería la excepción por la que se acaban colando las demás
     * (`REQ-ORG-002` RB-1).
     *
     * Que no haya tasa escrita **no es un error de este servicio**: devuelve
     * `null` igual que cuando no hay histórico, y quien necesite una para
     * conciliar es quien lanza.
     */
    private function manualFor(Organization $organization): ?RateSnapshot
    {
        try {
            return $this->context->runFor(
                $organization,
                fn (): RateSnapshot => $this->manual->fetch(RateSource::Manual),
            );
        } catch (ExchangeRateUnavailableException) {
            return null;
        }
    }

    /**
     * La última fila persistida de esa fuente, por `published_at`.
     *
     * El desempate por `id` importa: dos filas de la misma fuente pueden
     * compartir `published_at` si el proveedor republicó con la misma marca, y
     * sin él la «última» dependería del orden que le apeteciera a MySQL.
     */
    private function latestPersisted(RateSource $source): ?RateSnapshot
    {
        $row = ExchangeRate::query()
            ->where('source', $source->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new RateSnapshot(
            rate: $row->rate,
            source: $row->source,
            publishedAt: $row->published_at,
            fetchedAt: $row->fetched_at,
        );
    }

    /**
     * Rehidrata lo cacheado, o `null` si la entrada no tiene la forma que
     * escribimos.
     *
     * Una entrada con otra forma es una de caché vieja tras un despliegue que
     * cambió el formato. Se ignora y se lee de la base de datos, que es lo
     * mismo que se hace cuando no hay nada: descartar es barato y equivocarse
     * con una tasa no lo es.
     *
     * @param  array<array-key, mixed>  $cached
     */
    private function snapshotFromCache(array $cached): ?RateSnapshot
    {
        $rate = $cached['rate'] ?? null;
        $source = $cached['source'] ?? null;
        $publishedAt = $cached['published_at'] ?? null;
        $fetchedAt = $cached['fetched_at'] ?? null;

        if (! is_string($rate) || ! is_string($source) || ! is_string($publishedAt) || ! is_string($fetchedAt)) {
            return null;
        }

        $parsed = RateSource::tryFrom($source);

        if ($parsed === null) {
            return null;
        }

        try {
            return new RateSnapshot(
                rate: $rate,
                source: $parsed,
                publishedAt: CarbonImmutable::parse($publishedAt),
                fetchedAt: CarbonImmutable::parse($fetchedAt),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ejecuta una operación de caché tolerando que el almacén esté caído.
     *
     * Se capturan `Throwable` y no solo las excepciones de conexión, por lo
     * mismo que en `Settings`: el contrato con quien llama es «la caché nunca
     * es el motivo de que esto falle», y acotar la captura a lo que hoy lanza
     * phpredis convertiría el cambio de driver de mañana en una caída de todos
     * los catálogos a la vez.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn|null
     */
    private function withoutFailing(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }
}
