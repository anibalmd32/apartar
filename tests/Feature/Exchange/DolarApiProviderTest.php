<?php

declare(strict_types=1);

use App\Domain\Exchange\DolarApiProvider;
use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;

function dolarApiPayload(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Exchange/dolarapi-dolares.json'));
}

function dolarApiProvider(): DolarApiProvider
{
    return new DolarApiProvider(
        app(HttpClient::class),
        'https://ve.dolarapi.com/v1/dolares',
        8,
        ['promedio', 'venta', 'compra'],
    );
}

it('lee el payload real del proveedor', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $bcv = dolarApiProvider()->fetch(RateSource::Bcv);

    expect($bcv->rate)->toBe('36.50000000');
    expect($bcv->source)->toBe(RateSource::Bcv);
    expect($bcv->publishedAt->equalTo(CarbonImmutable::parse('2026-09-10T14:30:00.000Z')))->toBeTrue();
});

it('normaliza la fecha de publicación a la zona de la aplicación', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $publishedAt = dolarApiProvider()->fetch(RateSource::Bcv)->publishedAt;

    expect($publishedAt->timezone->getName())->toBe(date_default_timezone_get());
    expect($publishedAt->equalTo(CarbonImmutable::parse('2026-09-10T14:30:00.000Z')))->toBeTrue();
});

it('trae oficial y paralelo con una sola petición', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $provider = dolarApiProvider();

    expect($provider->fetch(RateSource::Bcv)->rate)->toBe('36.50000000');
    expect($provider->fetch(RateSource::Parallel)->rate)->toBe('42.20000000');

    Http::assertSentCount(1);
});

it('vuelve a pedir la respuesta con una instancia nueva', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    dolarApiProvider()->fetch(RateSource::Bcv);
    dolarApiProvider()->fetch(RateSource::Bcv);

    Http::assertSentCount(2);
});

it('entrega proveedores distintos en cada resolución del contenedor', function (): void {
    expect(app(ExchangeRateProvider::class))->not->toBe(app(ExchangeRateProvider::class));
    expect(app(ExchangeRateProvider::class))->toBeInstanceOf(DolarApiProvider::class);
});

it('toma la cifra por prioridad promedio, venta, compra', function (array $entry, string $expected): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response([$entry + [
        'fuente' => 'oficial',
        'fechaActualizacion' => '2026-09-10T14:30:00.000Z',
    ]], 200)]);

    expect(dolarApiProvider()->fetch(RateSource::Bcv)->rate)->toBe($expected);
})->with([
    'promedio manda' => [['promedio' => 36.5, 'venta' => 37.0, 'compra' => 36.0], '36.50000000'],
    'sin promedio, venta' => [['promedio' => 0, 'venta' => 37.0, 'compra' => 36.0], '37.00000000'],
    'sin promedio ni venta, compra' => [['promedio' => 0, 'venta' => 0, 'compra' => 36.0], '36.00000000'],
    'promedio nulo cae a venta' => [['promedio' => null, 'venta' => 37.25], '37.25000000'],
    'la cifra como cadena' => [['promedio' => '36.75'], '36.75'],
]);

it('descarta la cotización con los tres campos a cero', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response([[
        'fuente' => 'oficial',
        'promedio' => 0,
        'venta' => 0,
        'compra' => 0,
        'fechaActualizacion' => '2026-09-10T14:30:00.000Z',
    ]], 200)]);

    expect(fn () => dolarApiProvider()->fetch(RateSource::Bcv))
        ->toThrow(ExchangeRateUnavailableException::class, 'ningún valor mayor que cero');
});

it('ignora las fuentes que no son nuestras', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $provider = dolarApiProvider();

    expect($provider->fetch(RateSource::Bcv)->rate)->toBe('36.50000000');
    expect($provider->supports(RateSource::Manual))->toBeFalse();
});

it('lanza cuando el payload deja de tener la forma acordada', function (mixed $payload, string $expected): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response($payload, 200)]);

    expect(fn () => dolarApiProvider()->fetch(RateSource::Bcv))
        ->toThrow(ExchangeRateUnavailableException::class, $expected);
})->with([
    'ya no es una lista' => [['dolares' => []], 'lista JSON'],
    'renombraron `fuente`' => [[['origen' => 'oficial', 'promedio' => 36.5]], 'fuente'],
    'desapareció el oficial' => [[['fuente' => 'paralelo', 'promedio' => 42.2, 'fechaActualizacion' => '2026-09-10T14:45:00.000Z']], 'falta la cotización "oficial"'],
    'renombraron `fechaActualizacion`' => [[['fuente' => 'oficial', 'promedio' => 36.5, 'updatedAt' => '2026-09-10']], 'falta `fechaActualizacion`'],
    'la fecha dejó de ser una fecha' => [[['fuente' => 'oficial', 'promedio' => 36.5, 'fechaActualizacion' => 'ayer por la tarde']], 'no es una fecha'],
]);

it('lanza cuando la API no responde', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response('', 503)]);

    expect(fn () => dolarApiProvider()->fetch(RateSource::Bcv))
        ->toThrow(ExchangeRateUnavailableException::class, 'respondió 503');
});

it('lanza cuando la conexión falla', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Connection timed out'));

    expect(fn () => dolarApiProvider()->fetch(RateSource::Bcv))
        ->toThrow(ExchangeRateUnavailableException::class, 'Connection timed out');
});

it('pide con el timeout de ocho segundos', function (): void {
    expect(config('services.exchange_rate.timeout'))->toBe(8);

    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    dolarApiProvider()->fetch(RateSource::Bcv);

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://ve.dolarapi.com/v1/dolares');
});

it('devuelve la tasa como cadena decimal', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    expect(dolarApiProvider()->fetch(RateSource::Parallel)->rate)->toBeString();
});
