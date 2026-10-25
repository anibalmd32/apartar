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

    public function test_it_returns_a_null_rate_when_there_is_none(): void
    {
        $this->getJson('/api/tasa?source=bcv')
            ->assertOk()
            ->assertJson(['rate' => null, 'source' => 'bcv', 'publishedAt' => null, 'isStale' => false]);
    }

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

        $this->getJson('/api/tasa')->assertOk()->assertJson(['source' => 'bcv', 'rate' => 36.5]);
    }

    public function test_it_rejects_the_manual_source(): void
    {
        $this->getJson('/api/tasa?source=manual')->assertStatus(422);
    }

    public function test_it_rejects_an_unknown_source(): void
    {
        $this->getJson('/api/tasa?source=binance')->assertStatus(422);
    }

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

    public function test_it_never_calls_the_external_api(): void
    {
        Http::fake();
        $this->seedRate(RateSource::Bcv, '36.50000000');

        $this->getJson('/api/tasa?source=bcv')->assertOk();
        $this->getJson('/api/tasa?source=parallel')->assertOk();

        Http::assertNothingSent();
    }

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
