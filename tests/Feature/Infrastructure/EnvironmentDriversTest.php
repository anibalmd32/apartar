<?php

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function environmentExampleValues(): array
{
    static $values = null;

    if ($values !== null) {
        return $values;
    }

    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $matches) !== 1) {
            continue;
        }

        $values[$matches[1]] = trim($matches[2], "\"'");
    }

    foreach ($values as $key => $value) {
        $values[$key] = preg_replace_callback(
            '/\$\{([A-Z][A-Z0-9_]*)\}/',
            fn (array $matches): string => $values[$matches[1]] ?? '',
            $value,
        ) ?? $value;
    }

    return $values;
}

function configurationSource(string $file): string
{
    return (string) file_get_contents(config_path($file.'.php'));
}

it('declara los cinco drivers en .env.example', function (string $variable, string $expected): void {
    expect(environmentExampleValues())->toHaveKey($variable);
    expect(environmentExampleValues()[$variable])->toBe($expected);
})->with([
    ['CACHE_STORE', 'redis'],
    ['SESSION_DRIVER', 'redis'],
    ['QUEUE_CONNECTION', 'redis'],
    ['BROADCAST_CONNECTION', 'reverb'],
    ['FILESYSTEM_DISK', 'local'],
]);

it('lee cada driver desde la variable que .env.example declara', function (string $file, string $key, string $variable): void {
    expect(configurationSource($file))->toContain("'{$key}' => env('{$variable}'");
})->with([
    ['cache', 'default', 'CACHE_STORE'],
    ['session', 'driver', 'SESSION_DRIVER'],
    ['queue', 'default', 'QUEUE_CONNECTION'],
    ['broadcasting', 'default', 'BROADCAST_CONNECTION'],
    ['filesystems', 'default', 'FILESYSTEM_DISK'],
]);

it('tiene definido el destino de cada driver', function (): void {
    expect(config('cache.stores'))->toHaveKey('redis');
    expect(config('queue.connections'))->toHaveKey('redis');
    expect(config('broadcasting.connections'))->toHaveKey('reverb');
    expect(config('filesystems.disks'))->toHaveKey('local');
    expect(config('database.redis'))->toHaveKey('default');
});

it('deja el disco local privado', function (): void {
    expect(config('filesystems.disks.local.visibility'))->not->toBe('public');
});

it('no usa database para colas ni file para caché en ningún entorno', function (): void {
    $values = environmentExampleValues();

    expect($values['QUEUE_CONNECTION'])->not->toBeIn(['database', 'sync']);
    expect($values['CACHE_STORE'])->not->toBeIn(['file', 'database']);
    expect($values['SESSION_DRIVER'])->not->toBeIn(['file', 'database', 'cookie']);
});

it('fija la zona horaria efectiva en America/Caracas', function (): void {
    expect(environmentExampleValues()['APP_TIMEZONE'])->toBe('America/Caracas');
    expect(configurationSource('app'))->toContain("'timezone' => env('APP_TIMEZONE'");

    expect(in_array('America/Caracas', DateTimeZone::listIdentifiers(), true))->toBeTrue();
});

it('fija el locale y su respaldo en es', function (): void {
    $values = environmentExampleValues();

    expect($values['APP_LOCALE'])->toBe('es');
    expect($values['APP_FALLBACK_LOCALE'])->toBe('es');
});

it('apunta la aplicación a http://localhost:8080', function (): void {
    expect(environmentExampleValues()['APP_URL'])->toBe('http://localhost:8080');
});

it('arranca en local con el depurador encendido', function (): void {
    $values = environmentExampleValues();

    expect($values['APP_ENV'])->toBe('local');
    expect($values['APP_DEBUG'])->toBe('true');
});

it('separa el host interno de reverb del que ve el navegador', function (): void {
    $values = environmentExampleValues();

    expect($values['REVERB_HOST'])->toBe('reverb');
    expect($values['REVERB_PORT'])->toBe('8080');

    expect($values['VITE_REVERB_HOST'])->toBe('localhost');
    expect($values['VITE_REVERB_PORT'])->toBe('8081');
});

it('difunde hacia el contenedor y no hacia el puerto publicado', function (): void {
    $broadcasting = configurationSource('broadcasting');

    expect($broadcasting)
        ->toContain("'host' => env('REVERB_HOST'")
        ->toContain("'port' => env('REVERB_PORT'")
        ->not->toContain('VITE_REVERB_HOST');
});

it('declara las cinco variables de reverb', function (string $variable): void {
    expect(environmentExampleValues())->toHaveKey($variable);
})->with(['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_SCHEME']);

it('deja las cookies no seguras en local y con SameSite lax', function (): void {
    $values = environmentExampleValues();

    expect($values['SESSION_SECURE_COOKIE'])->toBe('false');
    expect($values['SESSION_SAME_SITE'])->toBe('lax');
});
