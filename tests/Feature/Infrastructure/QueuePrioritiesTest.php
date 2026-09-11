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
 * El plan T-002 deja `routes/console.php` vacío a propósito.
 */
it('no registra todavía ninguna entrada del schedule', function (): void {
    $console = (string) file_get_contents(base_path('routes/console.php'));

    expect($console)
        ->not->toContain('Schedule::')
        ->not->toContain('->daily()')
        ->not->toContain('->hourly()');
});
