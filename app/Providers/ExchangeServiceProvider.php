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

class ExchangeServiceProvider extends ServiceProvider
{
    /**
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

        $this->app->scoped(CurrentRate::class);
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return key-of<self::DRIVERS>
     *
     * @throws InvalidArgumentException
     */
    private function driverOf(array $config): string
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || ! array_key_exists($driver, self::DRIVERS)) {
            throw new InvalidArgumentException(sprintf(
                'El driver de tasa "%s" no existe. EXCHANGE_RATE_DRIVER admite: %s. '
                .'`manual` no es un driver: es la tasa que fija cada negocio en sus ajustes.',
                is_string($driver) ? $driver : get_debug_type($driver),
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        return $driver;
    }

    /**
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
