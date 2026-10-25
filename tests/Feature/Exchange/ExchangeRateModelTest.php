<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('persiste una lectura de la tasa', function (): void {
    $rate = ExchangeRate::factory()->create();

    expect($rate->exists)->toBeTrue();
    expect($rate->source)->toBe(RateSource::Bcv);
    expect(ExchangeRate::query()->count())->toBe(1);
});

it('no es una tabla del tenant', function (): void {
    expect(Schema::hasColumn('exchange_rates', 'organization_id'))->toBeFalse();
    expect(class_uses_recursive(ExchangeRate::class))->not->toContain(BelongsToOrganization::class);
});

it('se consulta y se escribe sin ninguna organización fijada', function (): void {
    ExchangeRate::factory()->create();
    ExchangeRate::factory()->parallel()->create();

    expect(ExchangeRate::query()->count())->toBe(2);
});

it('devuelve la tasa como cadena decimal de escala 8', function (): void {
    $rate = ExchangeRate::factory()->create(['rate' => '42.5']);

    expect($rate->fresh()?->rate)->toBe('42.50000000');
    expect($rate->fresh()?->rate)->toBeString();
});

it('no castea ninguna columna a coma flotante', function (): void {
    $casts = (new ExchangeRate)->getCasts();

    expect(array_values($casts))->not->toContain('float');
    expect(array_values($casts))->not->toContain('double');
    expect($casts['rate'])->toBe('decimal:8');
});

it('devuelve las dos marcas de tiempo como CarbonImmutable', function (): void {
    $rate = ExchangeRate::factory()->create()->fresh();

    expect($rate?->published_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($rate?->fetched_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('castea la fuente al enum en los dos sentidos', function (): void {
    $rate = ExchangeRate::factory()->parallel()->create();

    expect($rate->fresh()?->source)->toBe(RateSource::Parallel);
    expect(ExchangeRate::query()->where('source', 'parallel')->count())->toBe(1);
});

it('siembra el caso de la tasa vieja envejeciendo published_at', function (): void {
    $rate = ExchangeRate::factory()->stale()->create()->fresh();

    expect($rate?->published_at->isBefore(CarbonImmutable::now()->subDay()))->toBeTrue();
    expect($rate?->fetched_at->isAfter(CarbonImmutable::now()->subHour()))->toBeTrue();
});
