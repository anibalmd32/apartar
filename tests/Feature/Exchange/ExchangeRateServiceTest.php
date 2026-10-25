<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ExplodingCacheStore;

uses(RefreshDatabase::class);

function rates(): ExchangeRateService
{
    return app(ExchangeRateService::class);
}

function persistRate(RateSource $source, string $rate, string $publishedAt = '2026-09-10 10:30:00'): ExchangeRate
{
    return ExchangeRate::query()->create([
        'source' => $source,
        'rate' => $rate,
        'published_at' => CarbonImmutable::parse($publishedAt),
    ]);
}

it('lee de la caché cuando está caliente', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');

    Cache::put(ExchangeRateService::cacheKeyFor(RateSource::Bcv), [
        'rate' => '99.00000000',
        'source' => 'bcv',
        'published_at' => CarbonImmutable::now()->toIso8601String(),
        'fetched_at' => CarbonImmutable::now()->toIso8601String(),
    ], 60);

    expect(rates()->forSource(RateSource::Bcv)?->rate)->toBe('99.00000000');
});

it('cae a la base de datos con la caché vacía', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');

    Cache::flush();

    expect(rates()->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
});

it('calienta la caché al leer de la base de datos', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');
    Cache::flush();

    rates()->forSource(RateSource::Bcv);

    expect(Cache::get(ExchangeRateService::cacheKeyFor(RateSource::Bcv)))
        ->toMatchArray(['rate' => '36.50000000', 'source' => 'bcv']);
});

it('devuelve null cuando no hay nada en ninguna parte', function (): void {
    expect(rates()->forSource(RateSource::Bcv))->toBeNull();
});

it('devuelve la última publicada de esa fuente', function (): void {
    persistRate(RateSource::Bcv, '36.50000000', '2026-09-08 10:00:00');
    persistRate(RateSource::Bcv, '38.00000000', '2026-09-10 10:00:00');
    persistRate(RateSource::Bcv, '37.00000000', '2026-09-09 10:00:00');

    Cache::flush();

    expect(rates()->forSource(RateSource::Bcv)?->rate)->toBe('38.00000000');
});

it('no mezcla las fuentes', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');
    persistRate(RateSource::Parallel, '42.20000000');

    Cache::flush();

    expect(rates()->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
    expect(rates()->forSource(RateSource::Parallel)?->rate)->toBe('42.20000000');
});

it('responde con el almacén de caché caído', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');

    $store = new ExplodingCacheStore;
    Cache::extend('exploding', fn (): Illuminate\Cache\Repository => Cache::repository($store));
    config(['cache.default' => 'exploding', 'cache.stores.exploding' => ['driver' => 'exploding']]);
    Cache::forgetDriver('exploding');

    expect(app(ExchangeRateService::class)->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
    expect($store->attempts)->toBeGreaterThan(0);
});

it('ignora una entrada de caché con la forma antigua', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');

    Cache::put(ExchangeRateService::cacheKeyFor(RateSource::Bcv), ['rate' => 99.0], 60);

    expect(rates()->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
});

it('resuelve la fuente que cada organización tiene configurada', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');
    persistRate(RateSource::Parallel, '42.20000000');
    Cache::flush();

    $conservative = Organization::factory()->create();
    $street = Organization::factory()->create();

    setRateSource($street, RateSource::Parallel);

    expect(rates()->current($conservative)?->rate)->toBe('36.50000000');
    expect(rates()->current($street)?->rate)->toBe('42.20000000');
});

it('usa la fuente de plataforma sin organización', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');
    Cache::flush();

    expect(rates()->current()?->source)->toBe(RateSource::Bcv);
});

it('resuelve la tasa manual desde los ajustes de la organización', function (): void {
    $organization = Organization::factory()->create();

    setRateSource($organization, RateSource::Manual);
    setSetting($organization, SettingKey::ExchangeRateManual, '55.00000000');

    $snapshot = rates()->current($organization);

    expect($snapshot?->rate)->toBe('55.00000000');
    expect($snapshot?->source)->toBe(RateSource::Manual);
    expect(ExchangeRate::query()->count())->toBe(0);
});

it('devuelve null si la organización eligió manual y no escribió ninguna', function (): void {
    $organization = Organization::factory()->create();

    setRateSource($organization, RateSource::Manual);
    setSetting($organization, SettingKey::ExchangeRateManual, null);

    expect(rates()->current($organization))->toBeNull();
});

it('cae a la fuente de plataforma con un ajuste que ya no existe', function (): void {
    persistRate(RateSource::Bcv, '36.50000000');
    Cache::flush();

    $organization = Organization::factory()->create();
    setSetting($organization, SettingKey::ExchangeRateSource, 'binance');

    expect(rates()->sourceFor($organization))->toBe(RateSource::Bcv);
    expect(rates()->current($organization)?->rate)->toBe('36.50000000');
});

it('fecha la tasa manual con su última edición', function (): void {
    $organization = Organization::factory()->create();

    setRateSource($organization, RateSource::Manual);
    setSetting($organization, SettingKey::ExchangeRateManual, '55.00000000');

    app(TenantContext::class)->runFor($organization, function (): void {
        OrganizationSetting::query()
            ->where('key', SettingKey::ExchangeRateManual->value)
            ->update(['updated_at' => CarbonImmutable::now()->subDays(21)]);
    });

    $snapshot = rates()->current($organization);

    expect($snapshot?->isStale())->toBeTrue();
    expect($snapshot?->publishedAt->diffInDays(CarbonImmutable::now()))->toBeGreaterThanOrEqual(20);
});

function setRateSource(Organization $organization, RateSource $source): void
{
    setSetting($organization, SettingKey::ExchangeRateSource, $source->value);
}

function setSetting(Organization $organization, SettingKey $key, mixed $value): void
{
    app(TenantContext::class)->runFor($organization, function () use ($key, $value): void {
        OrganizationSetting::query()->updateOrCreate(['key' => $key->value], ['value' => $value]);
    });

    app(App\Support\Settings::class)->forget($organization);
}
