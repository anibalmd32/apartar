<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\DolarApiProvider;
use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ManualRateProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Resuelve el proveedor de tasa a partir de la configuración
 * (`REQ-FX-003` RB-5).
 *
 * Añadir un proveedor nuevo es escribir la clase y añadir una entrada aquí; no
 * exige tocar el job, ni el servicio, ni ninguna pantalla. Ese es todo el
 * propósito de que `ExchangeRateProvider` sea una interfaz.
 *
 * ## Falla al arrancar, no en tiempo de request
 *
 * SP-5 lo pide explícitamente, y el motivo es que un driver mal escrito en el
 * `.env` es un error de despliegue, no de una petición concreta. Si se
 * resolviera perezosamente, el síntoma sería el primer catálogo que alguien
 * abriera tres horas después de desplegar, con un error que no menciona el
 * `.env` por ninguna parte. Reventando en el arranque, lo ve quien despliega.
 *
 * ## Por qué el binding no es singleton
 *
 * `DolarApiProvider` memoriza la respuesta HTTP para servir las dos fuentes con
 * una sola petición (RB-0). Ese memo tiene que morir con la ejecución del job:
 * el worker es un proceso de vida larga y una instancia compartida serviría la
 * respuesta de hace media hora en la vuelta siguiente —para siempre, y sin que
 * nada fallara—. Cada resolución entrega un proveedor nuevo y limpio.
 */
class ExchangeServiceProvider extends ServiceProvider
{
    /**
     * Drivers disponibles, por el nombre con el que se escriben en el `.env`.
     *
     * `manual` no está aquí a propósito: no es un driver de plataforma sino la
     * tasa que fija cada negocio, y `ExchangeRateService` la resuelve por su
     * cuenta con {@see ManualRateProvider} cuando la organización la tiene
     * elegida. Ponerla como driver haría que la ingesta intentara consultar
     * una API que no existe.
     *
     * @var array<string, class-string<ExchangeRateProvider>>
     */
    public const DRIVERS = [
        'dolarapi' => DolarApiProvider::class,
    ];

    public function register(): void
    {
        $this->app->bind(ExchangeRateProvider::class, function (Application $app): ExchangeRateProvider {
            /** @var array{driver?: mixed, url?: mixed, timeout?: mixed, field_priority?: mixed} $config */
            $config = $app->make('config')->get('services.exchange_rate', []);

            return match ($this->driverOf($config)) {
                'dolarapi' => new DolarApiProvider(
                    $app->make(HttpClient::class),
                    is_string($config['url'] ?? null) ? $config['url'] : '',
                    is_int($config['timeout'] ?? null) ? $config['timeout'] : 8,
                    $this->fieldPriorityOf($config),
                ),
            };
        });

        $this->app->singleton(ManualRateProvider::class);

        // `scoped()` y no `singleton()`: el memo de la tasa tiene que morir
        // con la petición. Un singleton sobreviviría en el worker de colas
        // —un proceso de vida larga— y serviría la tasa de hace media hora en
        // el job siguiente, que es la misma trampa que RB-5b dejó escrita para
        // el proveedor. Laravel limpia los *scoped* entre peticiones y entre
        // jobs.
        $this->app->scoped(CurrentRate::class);
    }

    /**
     * Comprueba el driver configurado y devuelve su nombre.
     *
     * La comprobación vive aquí y no en el `match` para que el mensaje diga
     * qué se puso y qué se admite: un `UnhandledMatchError` con el nombre del
     * driver dentro es cierto pero no ayuda a quien acaba de escribirlo mal.
     *
     * @param  array<array-key, mixed>  $config
     * @return key-of<self::DRIVERS>
     *
     * @throws InvalidArgumentException si el driver no existe
     */
    private function driverOf(array $config): string
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || ! array_key_exists($driver, self::DRIVERS)) {
            throw new InvalidArgumentException(sprintf(
                'El driver de tasa "%s" no existe. EXCHANGE_RATE_DRIVER admite: %s. '
                .'`manual` no es un driver: es la tasa que fija cada negocio en sus ajustes (REQ-FX-003 RB-3).',
                is_string($driver) ? $driver : get_debug_type($driver),
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        return $driver;
    }

    /**
     * Orden en que se busca la cifra dentro de una cotización ([05 §6.2]).
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    private function fieldPriorityOf(array $config): array
    {
        $priority = $config['field_priority'] ?? null;

        if (! is_array($priority)) {
            return ['promedio', 'venta', 'compra'];
        }

        return array_values(array_filter($priority, 'is_string'));
    }
}
