<?php

declare(strict_types=1);

use Tests\Support\ComposeFile;

/**
 * Topología del stack local (REQ-INF-001).
 */
it('declara los siete servicios de la tabla de contratos', function (): void {
    expect(ComposeFile::merged()->keys('services'))
        ->toEqualCanonicalizing(['app', 'nginx', 'mysql', 'redis', 'queue', 'reverb', 'node']);
});

it('declara los tres volúmenes nombrados', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->keys('volumes'))
        ->toEqualCanonicalizing(['mysql_data', 'redis_data', 'app_storage']);
});

/**
 * Contenido de los dos archivos sin comentarios: lo que `docker compose`
 * realmente interpreta. Los comentarios sí nombran a Traefik, para explicar
 * por qué no está.
 */
function composeDirectives(): string
{
    $contents = file_get_contents(base_path('docker-compose.yml'))."\n"
        .file_get_contents(base_path('docker-compose.override.yml'));

    $lines = array_filter(
        preg_split('/\R/', $contents) ?: [],
        fn (string $line): bool => ! str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

it('usa una sola red y no conoce traefik_proxy en local', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->keys('networks'))->toBe(['apartar']);

    // REQ-INF-001 RB-0 y RB-3: ni red externa, ni etiquetas, ni TLS.
    expect(composeDirectives())
        ->not->toContain('traefik')
        ->not->toContain('external: true')
        ->not->toContain('labels:');
});

it('no crea el archivo de producción, diferido a T-087', function (): void {
    expect(file_exists(base_path('docker-compose.prod.yml')))->toBeFalse();
});

/**
 * REQ-INF-001 RB-5: healthcheck en mysql, redis y app, y dependencias que
 * esperan a que estén sanos antes de migrar.
 */
it('declara healthcheck en mysql, redis y app', function (string $service): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain('healthcheck:');
})->with(['mysql', 'redis', 'app']);

it('hace que app espere a mysql y redis sanos', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('app'))
        ->toContain('depends_on:')
        ->toContain('condition: service_healthy');
});

it('hace que queue y reverb esperen a que app esté sano', function (string $service): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain('app:')
        ->toContain('condition: service_healthy');
})->with(['queue', 'reverb']);

/**
 * REQ-INF-001 RB-6.
 */
it('declara restart y límite de memoria en todos los servicios', function (): void {
    $compose = ComposeFile::read('docker-compose.yml');

    foreach ($compose->keys('services') as $service) {
        expect($compose->service($service))
            ->toContain('restart: unless-stopped')
            ->toMatch('/mem_limit:\s*\S+/');
    }
});

/**
 * REQ-INF-001 RB-0 y RB-2: los puertos del host viven en el override, y son
 * exactamente los de 05 §9.1.
 */
it('publica los puertos de 05 §9.1 solo desde el override', function (): void {
    expect(file_get_contents(base_path('docker-compose.yml')))->not->toContain('ports:');

    $override = ComposeFile::read('docker-compose.override.yml');

    expect($override->service('nginx'))->toContain('"8080:80"');
    expect($override->service('mysql'))->toContain('"3307:3306"');
    expect($override->service('redis'))->toContain('"6380:6379"');
    expect($override->service('reverb'))->toContain('"8081:8080"');
    expect($override->service('node'))->toContain('"5173:5173"');
});

/**
 * REQ-INF-001 RB-4: el volumen de evidencia lo comparten app, queue y nginx.
 */
it('comparte app_storage entre app, queue y nginx', function (string $service): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain('app_storage:/var/www/html/storage/app');
})->with(['app', 'queue', 'nginx']);

/**
 * REQ-INF-001 RB-7 y SP-3: ningún servicio migra desde su comando; la decisión
 * es del entrypoint y depende del rol.
 */
it('no migra desde el comando de ningún servicio', function (): void {
    $compose = ComposeFile::merged();

    foreach ($compose->keys('services') as $service) {
        expect($compose->service($service))->not->toContain('migrate');
    }
});

it('da a cada servicio de la imagen propia su rol', function (string $service, string $role): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain("CONTAINER_ROLE: {$role}");
})->with([
    ['app', 'app'],
    ['queue', 'queue'],
    ['reverb', 'reverb'],
]);

/**
 * REQ-INF-005 RB-2: reverb escucha en el puerto interno; el override lo
 * publica en el 8081 para el navegador del host.
 */
it('arranca reverb en 0.0.0.0:8080 dentro de la red', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('reverb'))
        ->toContain('--host=0.0.0.0')
        ->toContain('--port=8080');
});

/**
 * REQ-INF-001 RB-8: los secretos viajan por .env, nunca en el compose.
 */
it('no versiona secretos en el compose', function (): void {
    $contents = file_get_contents(base_path('docker-compose.yml'))
        .file_get_contents(base_path('docker-compose.override.yml'));

    expect($contents)
        ->toContain('env_file:')
        ->not->toContain('APP_KEY=')
        ->not->toContain('GOOGLE_CLIENT_SECRET')
        ->not->toContain('VAPID_PRIVATE_KEY');
});
