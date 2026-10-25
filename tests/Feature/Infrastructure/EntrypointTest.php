<?php

declare(strict_types=1);

/**
 * @param  array<string, string>  $environment
 * @return array{status: int, output: string, errors: string}
 */
function runEntrypoint(array $environment, string ...$arguments): array
{
    $base = [
        'ENTRYPOINT_DRY_RUN' => '1',
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'CONTAINER_ROLE' => 'app',
    ];

    $process = proc_open(
        ['sh', base_path('docker/entrypoint.sh'), ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        array_merge($base, $environment),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('No se pudo ejecutar docker/entrypoint.sh.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'output' => $output, 'errors' => $errors];
}

it('es el artefacto con lógica del módulo y no un arranque de una línea', function (): void {
    $script = file_get_contents(base_path('docker/entrypoint.sh'));

    $significant = array_filter(
        preg_split('/\R/', $script) ?: [],
        fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#'),
    );

    expect(count($significant))->toBeGreaterThan(4);
});

it('migra cuando el rol es app', function (): void {
    $result = runEntrypoint(['CONTAINER_ROLE' => 'app']);

    expect($result['status'])->toBe(0);
    expect($result['output'])
        ->toContain('plan: migrate --force')
        ->toContain('plan: storage:link');
});

it('no migra cuando el rol es queue ni cuando es reverb', function (string $role): void {
    $result = runEntrypoint(['CONTAINER_ROLE' => $role]);

    expect($result['status'])->toBe(0);
    expect($result['output'])->not->toContain('plan: migrate');
})->with(['queue', 'reverb']);

it('levanta el scheduler junto al worker en el rol queue', function (): void {
    $result = runEntrypoint(['CONTAINER_ROLE' => 'queue']);

    expect($result['output'])->toContain('plan: schedule:work');
});

it('no levanta el scheduler en los otros roles', function (string $role): void {
    expect(runEntrypoint(['CONTAINER_ROLE' => $role])['output'])
        ->not->toContain('schedule:work');
})->with(['app', 'reverb']);

it('rechaza un rol desconocido', function (): void {
    $result = runEntrypoint(['CONTAINER_ROLE' => 'scheduler']);

    expect($result['status'])->not->toBe(0);
    expect($result['errors'])->toContain('CONTAINER_ROLE desconocido');
});

it('aborta con APP_DEBUG=true y APP_ENV=production', function (string $value): void {
    $result = runEntrypoint([
        'APP_ENV' => 'production',
        'APP_DEBUG' => $value,
        'SESSION_SECURE_COOKIE' => 'true',
    ]);

    expect($result['status'])->toBe(1);
    expect($result['errors'])->toContain('APP_DEBUG');
})->with(['true', '1', 'on', 'yes']);

it('permite producción con el depurador apagado', function (): void {
    $result = runEntrypoint([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_SECURE_COOKIE' => 'true',
    ]);

    expect($result['status'])->toBe(0);
});

it('exige cookies seguras en producción', function (): void {
    $result = runEntrypoint([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_SECURE_COOKIE' => 'false',
    ]);

    expect($result['status'])->toBe(1);
    expect($result['errors'])->toContain('SESSION_SECURE_COOKIE');
});

it('aborta sin APP_KEY y dice cómo generarla', function (): void {
    $result = runEntrypoint(['APP_KEY' => '']);

    expect($result['status'])->toBe(1);
    expect($result['errors'])->toContain('key:generate');
});

it('aplica optimize solo en producción', function (): void {
    expect(runEntrypoint(['APP_ENV' => 'local'])['output'])->not->toContain('plan: optimize');

    expect(runEntrypoint([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_SECURE_COOKIE' => 'true',
    ])['output'])->toContain('plan: optimize');
});

it('usa el comando recibido y, si no hay, el de su rol', function (): void {
    expect(runEntrypoint(['CONTAINER_ROLE' => 'app'])['output'])
        ->toContain('plan: exec php-fpm');

    expect(runEntrypoint(['CONTAINER_ROLE' => 'reverb'])['output'])
        ->toContain('plan: exec php artisan reverb:start');

    expect(runEntrypoint(['CONTAINER_ROLE' => 'queue'], 'php', 'artisan', 'queue:work', 'redis')['output'])
        ->toContain('plan: exec php artisan queue:work redis');
});
