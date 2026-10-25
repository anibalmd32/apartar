<?php

declare(strict_types=1);

use Tests\Support\ComposeFile;

it('declara las cuatro colas en orden estricto de prioridad', function (): void {
    expect(config('queue.priorities'))->toBe(['high', 'default', 'exports', 'telemetry']);
});

it('mantiene el comando del servicio queue alineado con la configuración', function (): void {
    $expected = '--queue='.implode(',', config('queue.priorities'));

    expect(ComposeFile::read('docker-compose.yml')->service('queue'))->toContain($expected);
});

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

it('pone high primero y telemetry último', function (): void {
    $priorities = config('queue.priorities');

    expect($priorities[0])->toBe('high');
    expect(end($priorities))->toBe('telemetry');
});

it('registra la ingesta de tasa cada treinta minutos', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn (Illuminate\Console\Scheduling\Event $event): string => $event->expression)
        ->all();

    expect($events)->toContain('*/30 * * * *');
});

it('encola la ingesta de tasa en la cola de mayor prioridad', function (): void {
    expect((new App\Jobs\FetchExchangeRate)->queue)->toBe('high');
});
