<?php

declare(strict_types=1);

use App\Domain\Exchange\DolarApiProvider;
use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;

/**
 * **El test de contrato con el proveedor** ([05 §6], `REQ-QAX-003` RB-9,
 * `REQ-FX-002` SP-4).
 *
 * El fixture es el payload real de `GET https://ve.dolarapi.com/v1/dolares`,
 * con la forma exacta que documenta [05 §6.1]. Es lo único del sistema que
 * afirma **cómo responde alguien que no controlamos**, y su trabajo es
 * convertir un cambio silencioso del proveedor en un test rojo.
 *
 * Sin él, el día que DolarApi renombrara `fechaActualizacion` o devolviera la
 * cifra como cadena, el síntoma no sería un error: sería el job fallando en
 * silencio cada media hora, la tasa envejeciendo, y los catálogos proyectando
 * con una cifra cada vez más vieja hasta que alguien reclamara por un precio.
 */
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

/**
 * **La fecha se normaliza a la zona de la aplicación en el borde.**
 *
 * DolarApi publica en UTC y el resto del sistema trabaja en `APP_TIMEZONE`.
 * Laravel escribe una columna `datetime` con el reloj de pared de la propia
 * fecha, sin convertirla, así que una fecha en UTC se guardaría como si fuese
 * local y volvería desplazada. El daño no sería solo una fecha mal puesta: la
 * deduplicación de RB-3 compara `published_at` con lo persistido y **nunca
 * volvería a coincidir**, de modo que cada vuelta del job insertaría una fila
 * nueva de la misma cotización.
 *
 * El instante es el mismo; lo que cambia es cómo se escribe.
 */
it('normaliza la fecha de publicación a la zona de la aplicación', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $publishedAt = dolarApiProvider()->fetch(RateSource::Bcv)->publishedAt;

    expect($publishedAt->timezone->getName())->toBe(date_default_timezone_get());
    expect($publishedAt->equalTo(CarbonImmutable::parse('2026-09-10T14:30:00.000Z')))->toBeTrue();
});

/**
 * RB-0 y [05 §6.1]: **una sola petición trae las dos fuentes**. El proveedor
 * memoriza la respuesta, que es lo que concilia esa regla con una interfaz que
 * pregunta por una fuente cada vez.
 */
it('trae oficial y paralelo con una sola petición', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $provider = dolarApiProvider();

    expect($provider->fetch(RateSource::Bcv)->rate)->toBe('36.50000000');
    expect($provider->fetch(RateSource::Parallel)->rate)->toBe('42.20000000');

    Http::assertSentCount(1);
});

/**
 * El memo muere con la instancia, y por eso el binding no es singleton: el
 * worker es un proceso de vida larga y una instancia compartida serviría la
 * respuesta de hace media hora en la vuelta siguiente, para siempre y sin que
 * nada fallara.
 */
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

/**
 * [05 §6.2]: **el primer valor mayor que cero** en el orden configurado. El
 * BCV publica `compra` y `venta` a cero porque no las tiene, y un cero ahí no
 * es un precio: es un hueco.
 */
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

/**
 * Las demás cotizaciones del proveedor se ignoran **en silencio** ([05 §6.2]):
 * no son un error de formato, son fuentes que no usamos. El fixture trae una a
 * propósito.
 */
it('ignora las fuentes que no son nuestras', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    $provider = dolarApiProvider();

    expect($provider->fetch(RateSource::Bcv)->rate)->toBe('36.50000000');
    expect($provider->supports(RateSource::Manual))->toBeFalse();
});

/**
 * **Lo que rompería si el proveedor cambiara el payload.** Cada caso es una
 * forma concreta de que el contrato se rompa, y todas acaban igual: una
 * excepción que el job trata como «no respondió» (SP-4), con el histórico
 * intacto.
 */
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

/**
 * SP-1 y SP-3: no respondió, respondió `5xx` o tardó de más. Los tres son el
 * mismo caso desde fuera y los tres se reintentan.
 */
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

/**
 * SP-3: el timeout es de 8 s porque esto corre en la cola `high` y una
 * petición colgada bloquearía al worker detrás de un servicio que no
 * controlamos.
 */
it('pide con el timeout de ocho segundos', function (): void {
    expect(config('services.exchange_rate.timeout'))->toBe(8);

    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    dolarApiProvider()->fetch(RateSource::Bcv);

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://ve.dolarapi.com/v1/dolares');
});

/**
 * La tasa nunca pasa por `float` a partir del borde: `json_decode` entrega
 * números y la conversión a cadena ocurre una sola vez, con la escala de la
 * columna.
 */
it('devuelve la tasa como cadena decimal', function (): void {
    Http::fake(['ve.dolarapi.com/*' => Http::response(dolarApiPayload(), 200)]);

    expect(dolarApiProvider()->fetch(RateSource::Parallel)->rate)->toBeString();
});
