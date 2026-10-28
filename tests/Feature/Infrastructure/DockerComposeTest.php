<?php

declare(strict_types=1);

use Tests\Support\ComposeFile;

it('declara los siete servicios de la tabla de contratos', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->keys('services'))
        ->toEqualCanonicalizing(['app', 'nginx', 'mysql', 'redis', 'queue', 'reverb', 'node']);
});

it('deja el MySQL de la suite solo en el modo dev', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->keys('services'))->not->toContain('mysql_test');
    expect(ComposeFile::read('docker-compose.override.yml')->keys('services'))->not->toContain('mysql_test');
    expect(ComposeFile::read('docker-compose.dev.yml')->keys('services'))->toContain('mysql_test');

    expect(composeDirectives())
        ->not->toContain('mysql_test')
        ->not->toContain('docker/mysql/init');

    expect(ComposeFile::read('docker-compose.dev.yml')->service('mysql_test'))
        ->toContain('tmpfs:')
        ->toContain('/var/lib/mysql:size=1g')
        ->toContain('mem_limit:');
});

it('declara los tres volúmenes nombrados', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->keys('volumes'))
        ->toEqualCanonicalizing(['mysql_data', 'redis_data', 'app_storage']);
});

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

    expect(composeDirectives())
        ->not->toContain('traefik')
        ->not->toContain('external: true')
        ->not->toContain('labels:');
});

it('produccion corre solo la app, detras de Traefik y con MySQL y Redis gestionados por Dokploy', function (): void {
    $production = ComposeFile::read('docker-compose.prod.yml');

    expect($production->keys('services'))->toEqualCanonicalizing(['app', 'nginx', 'queue', 'reverb']);
    expect($production->keys('volumes'))->toBe(['app_storage']);
    expect($production->block('networks', 'dokploy-network'))->toContain('external: true');
    expect((string) file_get_contents(base_path('docker-compose.prod.yml')))
        ->not->toContain('ports:')
        ->not->toContain('labels:')
        ->not->toContain('APP_KEY=');
});

it('en produccion cada servicio reinicia, tiene limite de memoria, su rol y la red de Dokploy', function (string $service, ?string $role): void {
    $block = ComposeFile::read('docker-compose.prod.yml')->service($service);

    expect($block)
        ->toContain('restart: unless-stopped')
        ->toMatch('/mem_limit:\s*\S+/')
        ->toContain('- dokploy-network');

    if ($role !== null) {
        expect($block)->toContain("CONTAINER_ROLE: {$role}")->toContain('env_file:');
    }
})->with([
    ['app', 'app'],
    ['nginx', null],
    ['queue', 'queue'],
    ['reverb', 'reverb'],
]);

it('en produccion comparte app_storage y construye los assets con el host publico de Reverb', function (): void {
    $production = ComposeFile::read('docker-compose.prod.yml');

    foreach (['app', 'queue', 'nginx'] as $service) {
        expect($production->service($service))->toContain('app_storage:/var/www/html/storage/app');
    }

    expect($production->service('nginx'))->toContain('target: web')->toContain('VITE_REVERB_HOST: ${VITE_REVERB_HOST}');

    foreach (['app', 'queue', 'reverb'] as $service) {
        expect($production->service($service))->toContain('target: runtime')->toContain('image: apartar/app');
    }
    expect($production->service('reverb'))->toContain('--host=0.0.0.0')->toContain('--port=8080');
});

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

it('declara restart y límite de memoria en todos los servicios', function (): void {
    $compose = ComposeFile::read('docker-compose.yml');

    foreach ($compose->keys('services') as $service) {
        expect($compose->service($service))
            ->toContain('restart: unless-stopped')
            ->toMatch('/mem_limit:\s*\S+/');
    }
});

it('publica los puertos de 05 §9.1 solo desde el override', function (): void {
    expect(file_get_contents(base_path('docker-compose.yml')))->not->toContain('ports:');

    $override = ComposeFile::read('docker-compose.override.yml');

    expect($override->service('nginx'))->toContain('"8080:80"');
    expect($override->service('mysql'))->toContain('"3307:3306"');
    expect($override->service('redis'))->toContain('"6380:6379"');
    expect($override->service('reverb'))->toContain('"8081:8080"');
    expect($override->service('node'))->toContain('"5173:5173"');
});

it('comparte app_storage entre app, queue y nginx', function (string $service): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain('app_storage:/var/www/html/storage/app');
})->with(['app', 'queue', 'nginx']);

it('no migra desde el comando de ningún servicio', function (): void {
    expect(composeDirectives())->not->toContain('migrate');
});

it('da a cada servicio de la imagen propia su rol', function (string $service, string $role): void {
    expect(ComposeFile::read('docker-compose.yml')->service($service))
        ->toContain("CONTAINER_ROLE: {$role}");
})->with([
    ['app', 'app'],
    ['queue', 'queue'],
    ['reverb', 'reverb'],
]);

it('arranca reverb en 0.0.0.0:8080 dentro de la red', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('reverb'))
        ->toContain('--host=0.0.0.0')
        ->toContain('--port=8080');
});

it('no versiona secretos en el compose', function (): void {
    $contents = file_get_contents(base_path('docker-compose.yml'))
        .file_get_contents(base_path('docker-compose.override.yml'));

    expect($contents)
        ->toContain('env_file:')
        ->not->toContain('APP_KEY=')
        ->not->toContain('GOOGLE_CLIENT_SECRET')
        ->not->toContain('VAPID_PRIVATE_KEY');
});

it('no monta el código del host en el stack por defecto', function (): void {
    expect(composeDirectives())
        ->not->toContain('.:/var/www/html')
        ->not->toContain('./public:');
});

it('sirve los assets compilados desde la imagen web de nginx', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('nginx'))
        ->toContain('target: web')
        ->toContain('VITE_REVERB_HOST');

    expect((string) file_get_contents(base_path('Dockerfile')))
        ->toContain('FROM nginx:1.27-alpine AS web')
        ->toContain('COPY --from=runtime /var/www/html/public /var/www/html/public');
});

it('deja Vite en caliente solo en el modo dev', function (): void {
    expect(ComposeFile::read('docker-compose.yml')->service('node'))->toContain('- dev');
    expect((string) file_get_contents(base_path('docker-compose.dev.yml')))->toContain('.:/var/www/html');
});

it('no depende de las credenciales del .env para los servicios locales', function (): void {
    $override = ComposeFile::read('docker-compose.override.yml');

    expect($override->service('mysql'))->toContain('MYSQL_USER: apartar');
    expect(ComposeFile::read('docker-compose.dev.yml')->service('mysql_test'))->toContain('MYSQL_USER: apartar');
    expect($override->service('app'))->toContain('DB_HOST: mysql')->toContain('REDIS_HOST: redis');
});
