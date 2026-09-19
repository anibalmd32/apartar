<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateView;
use Carbon\CarbonImmutable;

/**
 * La tasa tal como se pinta (contratos compartidos del módulo 13).
 *
 * Espejo exacto de `RateView` en `resources/js/types/exchange.ts`. Lo que se
 * fija aquí son las tres decisiones del tipo: qué fecha viaja, qué significa
 * `null`, y que la cifra sale como número para la pantalla y no como la cadena
 * con la que se opera.
 */
it('proyecta la tasa que hay', function (): void {
    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::parse('2026-09-18 09:00:00'),
        CarbonImmutable::parse('2026-09-18 09:30:00'),
    );

    expect(RateView::of($snapshot, RateSource::Bcv)->toArray())
        ->toMatchArray(['rate' => 42.5, 'source' => 'bcv']);
});

/**
 * **La fecha que viaja es `publishedAt`.** El sello de `REQ-FX-005` RB-2 dice
 * cuándo publicó la fuente, que es la antigüedad que le importa al negocio;
 * con `fetchedAt`, un fin de semana sin publicación estamparía «hoy 09:00»
 * sobre una tasa del viernes.
 */
it('viaja con la fecha de publicación y no con la de la ingesta', function (): void {
    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::parse('2026-09-18 09:00:00'),
        CarbonImmutable::parse('2026-09-18 23:30:00'),
    );

    $payload = RateView::of($snapshot, RateSource::Bcv)->toArray();

    expect($payload)->not->toHaveKey('fetchedAt');
    expect(CarbonImmutable::parse((string) $payload['publishedAt'])->format('H:i'))->toBe('09:00');
});

/**
 * `REQ-FX-005` RB-4: sin tasa, `rate` es `null` y **no cero**. La interfaz
 * oculta entonces el monto en bolívares por completo; un `0` pintaría
 * `Bs. 0,00`, que es un precio que nadie ha calculado.
 */
it('expresa la ausencia de tasa como null y nunca como cero', function (): void {
    $payload = RateView::of(null, RateSource::Parallel)->toArray();

    expect($payload['rate'])->toBeNull();
    expect($payload['rate'])->not->toBe(0.0);
    expect($payload['publishedAt'])->toBeNull();
    expect($payload)->toMatchArray(['source' => 'parallel', 'isStale' => false]);
});

/**
 * Sin tasa no hay obsolescencia que declarar: `isStale` en `true` sobre una
 * ausencia haría que la pantalla avisara de que «la tasa está vieja» donde no
 * hay ninguna tasa.
 */
it('no marca como obsoleta una ausencia', function (): void {
    expect(RateView::of(null, RateSource::Bcv)->isStale)->toBeFalse();
});

it('marca la obsolescencia sobre publishedAt', function (int $hours, bool $stale): void {
    // Con el reloj corriendo, «hace veinticuatro horas justas» deja de serlo
    // en los microsegundos que van de construir el snapshot a preguntarle: el
    // borde solo se puede afirmar con el tiempo congelado.
    CarbonImmutable::setTestNow('2026-09-18 12:00:00');

    $snapshot = new RateSnapshot(
        '42.50000000',
        RateSource::Bcv,
        CarbonImmutable::now()->subHours($hours),
        CarbonImmutable::now(),
    );

    expect(RateView::of($snapshot, RateSource::Bcv)->isStale)->toBe($stale);

    CarbonImmutable::setTestNow();
})->with([
    'recién publicada' => [1, false],
    'de hace un día justo' => [24, false],
    'de hace treinta horas' => [30, true],
]);

/**
 * La cifra sale como número porque el contrato de TypeScript dice
 * `number | null`, y es el único punto del sistema donde una tasa pasa por
 * coma flotante: aquí solo se pinta en el sello. La aritmética exacta se queda
 * del lado donde hay `bcmul`.
 */
it('entrega la tasa como número para la pantalla', function (): void {
    $snapshot = new RateSnapshot(
        '36.12345678',
        RateSource::Parallel,
        CarbonImmutable::now(),
        CarbonImmutable::now(),
    );

    $view = RateView::of($snapshot, RateSource::Parallel);

    expect($view->rate)->toBeFloat();
    expect($view->rate)->toBe(36.12345678);
});

/**
 * La fuente de la ausencia es **la que se pidió**: la pantalla tiene que poder
 * decir de qué fuente no hay tasa.
 */
it('conserva la fuente pedida cuando no hay tasa', function (): void {
    expect(RateView::of(null, RateSource::Manual)->source)->toBe(RateSource::Manual);
});
