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
 * La ingesta de la tasa (`REQ-FX-002`).
 *
 * Casi todo lo que hay aquí es un modo de fallo, y es lo que tiene que ser:
 * esta es la primera pieza del sistema que habla con el mundo exterior, así
 * que es la primera que puede romperse por algo que no controlamos.
 */

/**
 * Reemplaza el doble de HTTP entero, y no añade otro.
 *
 * `Http::fake()` **acumula** stubs y gana el primero que casa con la URL, así
 * que un segundo `fake()` sobre el mismo patrón no sustituye al primero: lo
 * deja debajo, sin efecto. Y estos tests necesitan justo eso —una vuelta del
 * job con una respuesta y la siguiente con otra— para poder comprobar la
 * deduplicación y la validación de plausibilidad, que solo existen cuando hay
 * un «antes».
 *
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

/**
 * **RB-0, la regla que ordena el diseño.** La ingesta es de plataforma y
 * única: con diez organizaciones sembradas tiene que seguir siendo **una sola
 * petición**, porque escalar a 10.000 negocios no puede multiplicar las
 * llamadas a una API que no controlamos.
 *
 * El test cuenta peticiones HTTP, no filas: es la forma de que la regla no se
 * erosione el día que alguien «arregle» el job iterando organizaciones.
 */
it('hace una sola petición aunque haya diez organizaciones', function (): void {
    Organization::factory()->count(10)->create();
    fakeDolarApi();

    runIngest();

    Http::assertSentCount(1);
    expect(Organization::query()->count())->toBe(10);
    expect(ExchangeRate::query()->count())->toBe(2);
});

/**
 * Y corre **sin organización fijada**: `exchange_rates` no es del tenant, así
 * que el job no tiene —ni debe tener— ninguna.
 */
it('corre sin ninguna organización fijada', function (): void {
    Organization::factory()->count(3)->create();
    fakeDolarApi();

    app(TenantContext::class)->forget();

    runIngest();

    expect(app(TenantContext::class)->hasOrganization())->toBeFalse();
    expect(ExchangeRate::query()->count())->toBe(2);
});

/**
 * RB-3, **y ojo con la conjunción**: no se inserta si la tasa **y** la fecha
 * de publicación son las mismas.
 */
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

/**
 * Una tasa idéntica con `published_at` nuevo **sí** es una fila nueva: el BCV
 * republicó, y el histórico tiene que poder demostrar que ese día la tasa
 * seguía siendo esa —no solo que lo era dos días antes—.
 */
it('inserta cuando la tasa es idéntica pero la fecha de publicación es otra', function (): void {
    fakeDolarApi();
    runIngest();

    fakeDolarApi(publishedAt: '2026-09-11T14:30:00.000Z');
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
});

/**
 * SP-2, la capa que más se olvida. Una tasa corrupta del proveedor no rompe
 * nada visiblemente: **reprecia el catálogo entero de todos los negocios** y
 * genera apartados con cifras que nadie va a poder explicar.
 */
it('rechaza una variación mayor al 30 % y conserva la anterior', function (): void {
    $log = Log::spy();

    // La referencia tiene que ser **reciente** para poder vetar (SP-2b): una
    // obsoleta se abstiene, y entonces esto probaría lo contrario de lo que
    // dice su nombre.
    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    // Más del doble: el proveedor publicó basura.
    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');

    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => str_contains($message, 'variación implausible'),
    )->once();
});

it('admite una variación que cabe en el 30 %', function (float $rate, int $rows): void {
    // Referencia reciente en las dos vueltas: lo que se mide aquí es el
    // umbral, no la abstención de SP-2b.
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

/**
 * Una tasa que no es positiva la para el **parser**, antes de que el job la
 * vea: `0` y las negativas no son precios, son huecos ([05 §6.2]).
 *
 * Y la cotización rota **se descarta sola**: el paralelo del mismo payload
 * sigue entrando. Perder las dos fuentes porque una venga mal sería dejar el
 * catálogo sin bolívares por un problema que solo afecta a la mitad.
 */
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

/**
 * La guarda de positividad **del job** es la última línea antes de persistir, y
 * existe aunque el parser de DolarApi ya filtre: `ExchangeRateProvider` es una
 * interfaz y lo que llegue de otra implementación no lo garantiza nadie. Se
 * ejercita con un proveedor de mentira, que es la única forma de llegar hasta
 * ella.
 */
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

/**
 * **Sin histórico no hay contra qué comparar.** La primera tasa de una fuente
 * solo tiene que ser positiva: exigirle además parecerse a algo que no existe
 * dejaría una instalación nueva sin poder ingestar nunca.
 */
it('acepta la primera tasa de una fuente por grande que sea', function (): void {
    fakeDolarApi(bcv: 9_999.0, parallel: 12_345.0);

    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('9999.00000000');
});

/**
 * SP-1: al fallar, **se conserva** lo que hubiera. Nunca se borra lo anterior
 * por no haber podido leer lo nuevo.
 */
it('conserva el histórico cuando la API se cae', function (): void {
    fakeDolarApi();
    runIngest();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response('', 503)]);

    expect(fn () => runIngest())->toThrow(ExchangeRateUnavailableException::class);

    expect(ExchangeRate::query()->count())->toBe(2);
    expect(ExchangeRate::query()->where('source', 'bcv')->value('rate'))->toBe('36.50000000');
});

/**
 * Y también la caché: `current()` sigue respondiendo con lo último bueno
 * aunque la ingesta lleve horas fallando (`REQ-FX-005` RB-1).
 */
it('conserva la caché cuando la API se cae', function (): void {
    fakeDolarApi();
    runIngest();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response('', 503)]);

    try {
        runIngest();
    } catch (ExchangeRateUnavailableException) {
        // El job falla y reintenta; lo que se comprueba es lo que queda.
    }

    expect(app(ExchangeRateService::class)->forSource(RateSource::Bcv)?->rate)->toBe('36.50000000');
});

/**
 * Si el payload trae una fuente y no la otra, persistir la que sí vino es
 * mejor que perder las dos; el fallo se relanza igualmente para que el job
 * reintente y quede en el log.
 */
it('persiste la fuente que sí vino y falla por la que falta', function (): void {
    $log = Log::spy();

    replaceDolarApiFake(['ve.dolarapi.com/*' => Http::response([
        ['fuente' => 'oficial', 'promedio' => 36.5, 'venta' => 0, 'compra' => 0, 'fechaActualizacion' => '2026-09-10T14:30:00.000Z'],
    ], 200)]);

    expect(fn () => runIngest())->toThrow(ExchangeRateUnavailableException::class);

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
    expect(ExchangeRate::query()->where('source', 'parallel')->count())->toBe(0);

    // Y queda dicho **cuál** faltó: un job que falla sin nombrar la fuente
    // obliga a reproducirlo a mano para saber qué mirar.
    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $context['sources'] === ['parallel'],
    )->once();
});

/**
 * RB-1: la caché se refresca **también cuando no se inserta fila**. Que la
 * cifra no haya cambiado no es motivo para dejar que la entrada caduque y
 * mandar a todo el mundo a la base de datos.
 */
it('refresca la caché aunque no inserte', function (): void {
    fakeDolarApi();
    runIngest();

    cache()->forget(ExchangeRateService::cacheKeyFor(RateSource::Bcv));

    fakeDolarApi();
    runIngest();

    expect(cache()->get(ExchangeRateService::cacheKeyFor(RateSource::Bcv)))
        ->toMatchArray(['rate' => '36.50000000', 'source' => 'bcv']);
});

/**
 * SP-6: `fetched_at` lo escribe la base de datos, no el proceso PHP, para que
 * un reloj de contenedor desfasado no falsee el diagnóstico de la ingesta.
 */
it('deja que la base de datos escriba fetched_at', function (): void {
    CarbonImmutable::setTestNow('2020-01-01 00:00:00');

    fakeDolarApi();
    runIngest();

    $row = ExchangeRate::query()->where('source', 'bcv')->firstOrFail();

    expect($row->fetched_at->year)->not->toBe(2020);
    expect($row->published_at->toDateString())->toBe('2026-09-10');

    CarbonImmutable::setTestNow();
});

/**
 * SP-5: el scheduler despacha a las :00 y a las :30, y un reintento con
 * backoff de 300 s puede caer justo encima.
 */
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

/**
 * La tabla es un histórico inmutable: solo `INSERT` (RB-2). Lo que el job
 * escribe son filas nuevas, nunca una actualización de las que ya estaban.
 */
it('no toca las filas ya escritas', function (): void {
    fakeDolarApi();
    runIngest();

    $first = ExchangeRate::query()->where('source', 'bcv')->firstOrFail();

    fakeDolarApi(bcv: 40.0, publishedAt: '2026-09-11T14:30:00.000Z');
    runIngest();

    expect($first->fresh()?->rate)->toBe('36.50000000');
    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
});

/**
 * **SP-2b · la guarda del 30 % exige una referencia reciente para poder
 * vetar.**
 *
 * Con la referencia fresca protege: un salto grande contra una tasa publicada
 * hace un rato es una fila corrupta del proveedor, y se rechaza.
 */
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

/**
 * Y con la referencia obsoleta **se abstiene**, que es lo que impide el cierre
 * permanente: si la ingesta o la fuente están caídas una semana y el bolívar
 * se mueve de verdad, sin esto *ninguna* lectura entraría jamás —el rechazo no
 * persiste nada, así que la referencia no avanza nunca— y la plataforma entera
 * se quedaría proyectando la tasa de la semana pasada.
 */
it('no veta el salto grande cuando su referencia ya está obsoleta', function (): void {
    $log = Log::spy();

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subDays(7)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subMinutes(10)->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(2);
    expect(app(ExchangeRateService::class)->forSource(RateSource::Bcv)?->rate)->toBe('90.00000000');

    // Aceptar por abstención **no es** aceptar por plausible: queda dicho, y
    // con `warning` para que se distinga del veto, que es `error`.
    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => str_contains($message, 'abstenida'),
    )->once();
});

/**
 * La abstención se decide con **el mismo `isStale()`** del sello y de la
 * alerta de plataforma, así que el borde es el mismo: a las 24 h justas la
 * referencia todavía vigila.
 */
it('mantiene el veto justo en el borde de las 24 h', function (): void {
    // Con el reloj corriendo, «hace veinticuatro horas justas» deja de serlo
    // en los microsegundos que van de persistir la referencia a consultarla, y
    // el borde solo se puede afirmar con el tiempo congelado.
    CarbonImmutable::setTestNow('2026-09-19 12:00:00');

    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(24)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->toIso8601String());
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

/**
 * Una aceptación normal **no escribe nada**: es lo que hace legible el log de
 * la abstención. Si cada ingesta dejara una línea, la que importa se perdería
 * entre cuarenta y ocho al día.
 */
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

/**
 * SP-2 · **el rechazo por implausibilidad no consume reintentos.** Volver a
 * pedir la misma respuesta absurda da la misma respuesta absurda, así que el
 * job termina bien y espera a su próxima vuelta en lugar de gastar los tres
 * intentos en media hora.
 */
it('no falla el job cuando rechaza una tasa por implausible', function (): void {
    fakeDolarApi(bcv: 36.5, publishedAt: CarbonImmutable::now()->subHours(2)->toIso8601String());
    runIngest();

    fakeDolarApi(bcv: 90.0, publishedAt: CarbonImmutable::now()->subHour()->toIso8601String());

    // Si consumiera reintentos, esto lanzaría y el job quedaría fallido.
    runIngest();

    expect(ExchangeRate::query()->where('source', 'bcv')->count())->toBe(1);
});
