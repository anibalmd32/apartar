<?php

declare(strict_types=1);

use Tests\Support\ComposeFile;

/**
 * Colas priorizadas (REQ-INF-003 RB-1, RB-2, RB-3 y RB-4).
 *
 * Las catorce entradas del schedule no están aquí: entran con la tarea de
 * cada job y cierran al final de H8. Lo que T-002 fija es el worker.
 */
it('declara las cuatro colas en orden estricto de prioridad', function (): void {
    expect(config('queue.priorities'))->toBe(['high', 'default', 'exports', 'telemetry']);
});

it('mantiene el comando del servicio queue alineado con la configuración', function (): void {
    $expected = '--queue='.implode(',', config('queue.priorities'));

    expect(ComposeFile::read('docker-compose.yml')->service('queue'))->toContain($expected);
});

/**
 * REQ-INF-003 RB-3 y RB-4.
 */
it('recicla el worker cada hora y reintenta tres veces', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('queue'))
        ->toContain('--tries=3')
        ->toContain('--max-time=3600');
});

it('consume desde redis y no desde la base de datos', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('queue'))
        ->toContain('queue:work')
        ->toContain('redis');
});

/**
 * REQ-INF-003 RB-2: el orden importa. Una lista alfabética o reordenada
 * rompería la prioridad sin que nada más lo note.
 */
it('pone high primero y telemetry último', function (): void {
    $priorities = config('queue.priorities');

    expect($priorities[0])->toBe('high');
    expect(end($priorities))->toBe('telemetry');
});

/**
 * Las catorce entradas del schedule de `REQ-INF-003` RB-5 entran **con la
 * tarea de cada job**, no todas de golpe, y cierran al final de H8.
 *
 * `T-002` dejó `routes/console.php` vacío a propósito y `T-020` registró la
 * primera: la ingesta de la tasa. Lo que se comprueba aquí es que existe y con
 * su cadencia; que el schedule esté completo es el criterio de cierre de
 * `REQ-INF-003`, no de esta comprobación.
 */
it('registra la ingesta de tasa cada treinta minutos', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn (Illuminate\Console\Scheduling\Event $event): string => $event->expression)
        ->all();

    expect($events)->toContain('*/30 * * * *');
});

/**
 * Corre en la cola `high`, que es la primera de la lista de prioridad: sin
 * tasa, ningún catálogo puede mostrar precios en bolívares.
 */
it('encola la ingesta de tasa en la cola de mayor prioridad', function (): void {
    expect((new App\Jobs\FetchExchangeRate)->queue)->toBe('high');
});
