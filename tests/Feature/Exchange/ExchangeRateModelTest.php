<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * `ExchangeRate` sobre la tabla de plataforma (`REQ-FX-002`).
 *
 * La migración es de `T-004`; lo que nace aquí es el modelo y su factory. Lo
 * que se fija es que **no** es del tenant y que la tasa no se convierte en
 * coma flotante en ningún tramo del camino.
 */
it('persiste una lectura de la tasa', function (): void {
    $rate = ExchangeRate::factory()->create();

    expect($rate->exists)->toBeTrue();
    expect($rate->source)->toBe(RateSource::Bcv);
    expect(ExchangeRate::query()->count())->toBe(1);
});

/**
 * La decisión de la tarea: `exchange_rates` es de plataforma. La tasa del BCV
 * es la misma para todo el mundo, y con `organization_id` la ingesta tendría
 * que escribir N filas por lectura.
 */
it('no es una tabla del tenant', function (): void {
    expect(Schema::hasColumn('exchange_rates', 'organization_id'))->toBeFalse();
    expect(class_uses_recursive(ExchangeRate::class))->not->toContain(BelongsToOrganization::class);
});

/**
 * Sin tenant fijado, un modelo del tenant lanza `MissingTenantContextException`
 * al consultar. Este no: es la prueba de que la ingesta de `T-020` puede correr
 * desde un job de plataforma sin organización ninguna.
 */
it('se consulta y se escribe sin ninguna organización fijada', function (): void {
    ExchangeRate::factory()->create();
    ExchangeRate::factory()->parallel()->create();

    expect(ExchangeRate::query()->count())->toBe(2);
});

/**
 * `REQ-FX-001` RB-1. El cast es `decimal:8` y devuelve **una cadena**: además
 * de cerrarle la puerta a la coma flotante, normaliza la escala, que es lo que
 * permitirá a `T-020` deduplicar (RB-3) comparando cadenas sin depender de
 * cómo viniera escrito el número en el JSON de la fuente.
 */
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

/**
 * `REQ-FX-002` RB-3b: las dos fechas, castadas a `CarbonImmutable` para que lo
 * que sale del modelo encaje con `RateSnapshot` sin conversiones intermedias.
 */
it('devuelve las dos marcas de tiempo como CarbonImmutable', function (): void {
    $rate = ExchangeRate::factory()->create()->fresh();

    expect($rate?->published_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($rate?->fetched_at)->toBeInstanceOf(CarbonImmutable::class);
});

/**
 * La fuente se guarda como su cadena y vuelve como enum: leer la columna cruda
 * obligaría a un `RateSource::from()` en cada sitio que la toca, que es donde
 * se cuela el que falta.
 */
it('castea la fuente al enum en los dos sentidos', function (): void {
    $rate = ExchangeRate::factory()->parallel()->create();

    expect($rate->fresh()?->source)->toBe(RateSource::Parallel);
    expect(ExchangeRate::query()->where('source', 'parallel')->count())->toBe(1);
});

/**
 * El estado de `REQ-FX-005` RB-3, sembrado por la factory: la ingesta funciona
 * —`fetched_at` es de hace cinco minutos— y aun así la tasa está obsoleta,
 * porque la fuente lleva treinta horas sin publicar.
 */
it('siembra el caso de la tasa vieja envejeciendo published_at', function (): void {
    $rate = ExchangeRate::factory()->stale()->create()->fresh();

    expect($rate?->published_at->isBefore(CarbonImmutable::now()->subDay()))->toBeTrue();
    expect($rate?->fetched_at->isAfter(CarbonImmutable::now()->subHour()))->toBeTrue();
});
