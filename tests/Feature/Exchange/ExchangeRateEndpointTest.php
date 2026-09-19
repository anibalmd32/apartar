<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/tasa?source=` (`REQ-FX-002` RB-5 y RB-6).
 *
 * Es lo que refresca una pestaña abierta cada cinco minutos sin recargar la
 * página. Público, cacheado, y **sin decir qué organización usa qué fuente**.
 *
 * Va como clase de PHPUnit y no con la sintaxis de Pest porque ejercita
 * métodos de instancia del caso base —`getJson()`—, y PHPStan nivel 6 no
 * resuelve el `$this` de las *closures* de Pest. Es la convención del
 * proyecto desde `T-004`.
 */
final class ExchangeRateEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_current_rate_shaped_as_a_rate_view(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa?source=bcv')
            ->assertOk()
            ->assertJsonStructure(['rate', 'source', 'publishedAt', 'isStale'])
            ->assertJson(['rate' => 36.5, 'source' => 'bcv', 'isStale' => true]);
    }

    public function test_it_falls_back_to_the_platform_source(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa')->assertOk()->assertJson(['source' => 'bcv']);
    }

    public function test_it_serves_each_public_source_separately(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');
        $this->seedRate(RateSource::Parallel, '42.20000000');

        $this->getJson('/api/tasa?source=bcv')->assertOk()->assertJson(['rate' => 36.5]);
        $this->getJson('/api/tasa?source=parallel')->assertOk()->assertJson(['rate' => 42.2]);
    }

    /**
     * `REQ-FX-005` RB-4: sin ninguna tasa, `rate` es `null` y no cero. La
     * interfaz oculta entonces el monto en bolívares por completo; un `0`
     * pintaría `Bs. 0,00`, que es un precio que nadie ha calculado.
     */
    public function test_it_returns_a_null_rate_when_there_is_none(): void
    {
        $this->getJson('/api/tasa?source=bcv')
            ->assertOk()
            ->assertJson(['rate' => null, 'source' => 'bcv', 'publishedAt' => null, 'isStale' => false]);
    }

    /**
     * La fecha que viaja es la de **publicación**: el sello dice cuándo publicó
     * la fuente, que es la antigüedad que le importa al negocio
     * (`REQ-FX-005` RB-2). `fetchedAt` no sale al cliente.
     */
    public function test_it_travels_with_published_at_and_not_with_the_ingest_time(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000', '2026-09-10 10:30:00');

        /** @var array<string, mixed> $payload */
        $payload = $this->getJson('/api/tasa?source=bcv')->assertOk()->json();

        $this->assertArrayNotHasKey('fetchedAt', $payload);
        $this->assertSame('2026-09-10', CarbonImmutable::parse((string) $payload['publishedAt'])->toDateString());
    }

    public function test_it_marks_a_recent_rate_as_fresh(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000', CarbonImmutable::now()->subHour()->toDateTimeString());

        $this->getJson('/api/tasa?source=bcv')->assertOk()->assertJson(['isStale' => false]);
    }

    /**
     * **RB-6: no revela qué organización usa cuál.** Si el endpoint resolviera
     * la fuente por su cuenta, cada respuesta diría de qué tasa se fía ese
     * negocio, que es información suya. Recibiéndola como parámetro no dice
     * nada que no estuviera ya en el sello público del catálogo.
     */
    public function test_it_does_not_reveal_the_source_of_any_organization(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');
        $this->seedRate(RateSource::Parallel, '42.20000000');

        $organization = Organization::factory()->create();

        app(TenantContext::class)->runFor($organization, function (): void {
            OrganizationSetting::query()->updateOrCreate(
                ['key' => SettingKey::ExchangeRateSource->value],
                ['value' => RateSource::Parallel->value],
            );
        });

        // Sin `source`, responde la de plataforma: la elección de ese negocio
        // no cambia lo que ve un tercero.
        $this->getJson('/api/tasa')->assertOk()->assertJson(['source' => 'bcv', 'rate' => 36.5]);
    }

    /**
     * Una tasa manual es de **una** organización concreta (`REQ-FX-003` RB-4),
     * así que servirla aquí exigiría decir de cuál, que es justo lo que RB-6
     * prohíbe. Viaja por las page props, donde ya hay sesión y contexto.
     */
    public function test_it_rejects_the_manual_source(): void
    {
        $this->getJson('/api/tasa?source=manual')->assertStatus(422);
    }

    public function test_it_rejects_an_unknown_source(): void
    {
        $this->getJson('/api/tasa?source=binance')->assertStatus(422);
    }

    /**
     * RB-6: público y cacheado 60 s. Es lo que absorbe la ráfaga de pestañas
     * que preguntan a la vez sin llevarlas todas a la base de datos.
     */
    public function test_it_is_public_and_cached_for_a_minute(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa?source=bcv')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public');

        $this->assertSame(60, ExchangeRateController::CACHE_SECONDS);
    }

    public function test_it_answers_without_a_session(): void
    {
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa?source=bcv')->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    /**
     * `REQ-FX-005` SP-4: **el endpoint nunca consulta al proveedor externo**.
     * Si una petición web pudiera disparar la llamada a DolarApi, el tiempo de
     * respuesta de esto —que se invoca cada cinco minutos desde cada pestaña
     * abierta— pasaría a depender de un servicio de terceros.
     */
    public function test_it_never_calls_the_external_api(): void
    {
        Http::fake();
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa?source=bcv')->assertOk();
        $this->getJson('/api/tasa?source=parallel')->assertOk();

        Http::assertNothingSent();
    }

    /**
     * Las fuentes admitidas se derivan del enum quitando la manual, y no se
     * escriben a mano: con dos censos, el día que aparezca una cuarta fuente
     * pública uno de los dos se quedaría corto.
     */
    public function test_it_derives_the_allowed_sources_from_the_enum(): void
    {
        $this->assertSame([RateSource::Bcv, RateSource::Parallel], ExchangeRateController::publicSources());
    }

    private function seedRate(RateSource $source, string $rate, string $publishedAt = '2026-09-10 10:30:00'): void
    {
        ExchangeRate::query()->create([
            'source' => $source,
            'rate' => $rate,
            'published_at' => CarbonImmutable::parse($publishedAt),
        ]);

        Cache::flush();
    }
}
