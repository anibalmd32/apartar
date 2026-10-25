<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeRateProvider;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ExchangeRateUnavailableException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\FetchExchangeRate;
use App\Models\ExchangeRate;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * @param  callable|array<string, mixed>  $stub
 */
function replaceDolarApiFake(callable|array $stub): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake($stub);
}

function fakeDolarApi(float $bcv = 36.5, float $parallel = 42.2, string $publishedAt = '2026-09-10T14:30:00.000Z'): void
{
    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response([
        ['fuente' => 'oficial', 'promedio' => $bcv, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => $publishedAt],
        ['fuente' => 'paralelo', 'promedio' => $parallel, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => $publishedAt],
    ], 200)]);
}

function runIngest(): void
{
    app()->call([new FetchExchangeRate, 'handle']);
}

it('persiste las dos fuentes públicas', function (): void {
    fakeDolarApi();

    runIngest();

    expect(ExchangeRate::query()->count())->toBe(2);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');
    expect(ExchangeRate::query()->where('source', 'parallel')->value('rate'))->toBe('42.20000000');
});

it('hace una sola petición aunque haya diez organizaciones', function (): void {
    Organization::factory()->count(10)->create();
    fakeDolarApi();

    runIngest();

    Http::assertSentCount(1);
    expect(Organization::query()->count())->toBe(10);
    expect(ExchangeRate::query()->count())->toBe(2);
});

it('corre sin ninguna organización fijada', function (): void {
    Organization::factory()->count(3)->create();
    fakeDolarApi();

    app(TenantContext::class)->forget();

    runIngest();

    expect(app(TenantContext::class)->hasOrganization())->toBeFalse();
    expect(ExchangeRate::query()->count())->toBe(2);
});

it('no inserta una fila nueva si no cambió nada', function (): void {
    fakeDolarApi();
    runIngest();

    fakeDolarApi();
    runIngest();

    expect(ExchangeRate::query()->count())->toBe(2);
});

it('inserta cuando cambia la tasa', function (): void {
    fakeDolarApi();
    runIngest();

    fakeDolarApi(bcv: 37.1);
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
});

it('inserta cuando la tasa es idéntica pero la fecha de publicación es otra', function (): void {
    fakeDolarApi();
    runIngest();

    fakeDolarApi(publishedAt: '2026-09-11T14:30:00.000Z');
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
});

it('rechaza una variación mayor al 30 % y conserva la anterior', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');

    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => str_contains($message, 'variación implausible'),
    )->once();
});

it('admite una variación que cabe en el 30 %', function (float $rate, int $rows): void {
    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: $rate, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe($rows);
})->with([
    'subida del 10 %' => [40.15, 2],
    'bajada del 10 %' => [32.85, 2],
    'justo en el 30 %' => [47.45, 2],
    'un pelo por encima del 30 %' => [47.46, 1],
    'bajada del 50 %' => [18.25, 1],
]);

it('descarta la cotización no positiva sin perder la otra fuente', function (float $rate): void {
    fakeDolarApi(bcv: 36.5);
    runIngest();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response([
        ['fuente' => 'oficial', 'promedio' => $rate, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-11T14:30:00.000Z'],
        ['fuente' => 'paralelo', 'promedio' => 50.0, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-11T14:30:00.000Z'],
    ], 200)]);

    expect(fn () => runIngest())->toThrow(ExchangeRateUnavailableException::class, 'ningún valor mayor que cero');

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');
    expect(ExchangeRate::query()->where('source', 'parallel')->count())->toBe(2);
})->with(['cero' => [0.0], 'negativa' => [-5.0]]);

it('rechaza en el job una tasa no positiva que se colara de otro proveedor', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5);
    runIngest();

    app()->bind(ExchangeRateProvider::class, fn (): ExchangeRateProvider => new class implements ExchangeRateProvider
    {
        public function fetch(RateSource $source): RateSnapshot
        {
            return new RateSnapshot('-1.00000000', $source, CarbonImmutable::now(), CarbonImmutable::now());
        }

        public function supports(RateSource $source): bool
        {
            return true;
        }
    });

    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');

    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => str_contains($message, 'no ser positiva'),
    )->twice();
});

it('acepta la primera tasa de una fuente por grande que sea', function (): void {
    fakeDolarApi(bcv: 9_999.0, parallel: 12_345.0);

    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('9999.00000000');
});

it('conserva el histórico cuando la API se cae', function (): void {
    fakeDolarApi();
    runIngest();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response('', 503)]);

    expect(fn () => runIngest())->toThrow(ExchangeRateUnavailableException::class);

    expect(ExchangeRate::query()->count())->toBe(2);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');
});

it('conserva la caché cuando la API se cae', function (): void {
    fakeDolarApi();
    runIngest();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response('', 503)]);

    try {
        runIngest();
    } catch (ExchangeRateUnavailableException) {
    }

    expect(app(ExchangeRateService::class)->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
});

it('persiste la fuente que sí vino y falla por la que falta', function (): void {
    $log = Log::spy();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response([
        ['fuente' => 'oficial', 'promedio' => 36.5, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-10T14:30:00.000Z'],
    ], 200)]);

    expect(fn () => runIngest())->toThrow(ExchangeRateUnavailableException::class);

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'parallel')->count())->toBe(0);

    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $context['sources'] === ['parallel'],
    )->once();
});

it('refresca la caché aunque no inserte', function (): void {
    fakeDolarApi();
    runIngest();

    cache()->forget(ExchangeRateService::cacheKeyFor(RateSource::Bcv));

    fakeDolarApi();
    runIngest();

    expect(cache()->get(ExchangeRateService::cacheKeyFor(RateSource::Bcv)))
        ->toMatchArray(['rate' => '36.50000000', 'source' => 'bcv']);
});

it('deja que la base de datos escriba fetched_at', function (): void {
    CarbonImmutable::setTestNow('2020-01-01 00:00:00');

    fakeDolarApi();
    runIngest();

    $row = ExchangeRate::query()->where('source', 'bcv')->firstOrFail();

    expect($row->fetched_at->year)->not->toBe(2020);
    expect($row->published_at->toDateString())->toBe('2026-09-10');

    CarbonImmutable::setTestNow();
});

it('aplica WithoutOverlapping', function (): void {
    $middleware = (new FetchExchangeRate)->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('reintenta tres veces con el backoff de la spec', function (): void {
    $job = new FetchExchangeRate;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([10, 60, 300]);
});

it('va en la cola de mayor prioridad', function (): void {
    expect((new FetchExchangeRate)->queue)->toBe('high');
});

it('no toca las filas ya escritas', function (): void {
    fakeDolarApi();
    runIngest();

    $first = ExchangeRate::query()->where('source', 'bcv')->firstOrFail();

    fakeDolarApi(bcv: 40.0, publishedAt: '2026-09-11T14:30:00.000Z');
    runIngest();

    expect($first->fresh()?->rate)->toBe('36.50000000');
    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
});

it('veta el salto grande cuando la referencia está fresca', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');

    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => str_contains($message, 'variación implausible'),
    )->once();
});

it('no veta el salto grande cuando su referencia ya está obsoleta', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subDays(7)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subMinutes(10)->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
    expect(app(ExchangeRateService::class)->forSource(RateSource::Bcv)?->rate)->toBe('90.00000000');

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => str_contains($message, 'abstenida'),
    )->once();
});

it('mantiene el veto justo en el borde de las 24 h', function (): void {
    CarbonImmutable::setTestNow('2026-09-19 12:00:00');

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(24)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('no registra nada cuando la tasa entra por plausible', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5);
    runIngest();

    fakeDolarApi(bcv: 37.0, publishedAt: '2026-09-11T14:30:00.000Z');
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);

    $log->shouldNotHaveReceived('warning');
    $log->shouldNotHaveReceived('error');
});

it('no falla el job cuando rechaza una tasa por implausible', function (): void {
    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());

    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
});
