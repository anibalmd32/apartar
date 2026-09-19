<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Exchange\RateSource;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lecturas de la tasa para los tests.
 *
 * **No usa `CreatesWithinTenant` y eso es correcto**: `exchange_rates` es una
 * tabla de plataforma y `ExchangeRate` no lleva `BelongsToOrganization`, así
 * que no hay organización que fijar. La comprobación RB-4c de `tenancy:audit`
 * solo exige el trait a las factories de modelos del tenant, que es por lo que
 * esta pasa el gate sin excepción escrita a mano.
 *
 * La tasa se escribe **como cadena**, igual que viaja por todo el sistema. Una
 * factory que la sembrara como `float` metería la coma flotante justo en el
 * sitio donde nadie la busca —los datos de prueba—, y los tests dejarían de
 * ejercitar el tipo que de verdad se persiste.
 *
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /** @var class-string<ExchangeRate> */
    protected $model = ExchangeRate::class;

    /**
     * @return array<model-property<ExchangeRate>, mixed>
     */
    public function definition(): array
    {
        $publishedAt = CarbonImmutable::now()->subMinutes(15);

        return [
            'source' => RateSource::Bcv,
            'rate' => '42.50000000',
            'published_at' => $publishedAt,
            'fetched_at' => $publishedAt->addMinutes(10),
        ];
    }

    /**
     * Cotización del mercado paralelo, la otra que sí es de plataforma.
     */
    public function parallel(): static
    {
        return $this->state(fn (): array => [
            'source' => RateSource::Parallel,
            'rate' => '47.80000000',
        ]);
    }

    /**
     * Tasa publicada hace más de 24 h: el caso de `REQ-FX-005` RB-3.
     *
     * Envejece **`published_at`** y deja `fetched_at` reciente, que es
     * exactamente el escenario que distingue las dos columnas: la ingesta
     * funciona, la fuente lleva días sin moverse, y lo que hay que avisar es
     * lo segundo.
     */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'published_at' => CarbonImmutable::now()->subHours(30),
            'fetched_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }
}
