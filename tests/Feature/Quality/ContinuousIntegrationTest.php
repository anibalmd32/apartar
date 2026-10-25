<?php

declare(strict_types=1);

function workflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
}

function workflowDirectives(): string
{
    $lines = array_filter(
        preg_split('/\R/', workflow()) ?: [],
        fn (string $line): bool => ! str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

function workflowJob(string $name): string
{
    $directives = workflowDirectives();
    $start = strpos($directives, "\n  {$name}:\n");

    expect($start)->not->toBeFalse("El job {$name} no está declarado.");

    $rest = substr($directives, (int) $start + 1);
    $next = preg_split('/\n  [a-z][a-z0-9_-]*:\n/', $rest, 2);

    return $next[0];
}

it('declara los tres jobs', function (string $job): void {
    expect(workflowDirectives())->toContain("\n  {$job}:\n");
})->with(['backend', 'frontend', 'tenancy']);

it('corre los tres jobs en paralelo', function (): void {
    expect(workflowDirectives())->not->toContain('needs:');
});

it('mantiene tenancy independiente de backend', function (): void {
    expect(workflowJob('tenancy'))
        ->toContain('composer run tenancy:suite')
        ->not->toContain('needs:');

    expect(workflowJob('backend'))->not->toContain('--testsuite=Tenancy');
});

it('ejecuta el job tenancy en su orden', function (): void {
    $tenancy = workflowJob('tenancy');

    $steps = ['artisan migrate', 'composer run tenancy:audit', 'composer run tenancy:suite'];
    $positions = array_map(fn (string $step): int => (int) strpos($tenancy, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job tenancy.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

it('no marca ningún paso como continuar en caso de error', function (): void {
    expect(workflowDirectives())
        ->not->toContain('continue-on-error')
        ->not->toContain('if: always()');
});

it('ejecuta el job backend en su orden', function (): void {
    $backend = workflowJob('backend');

    $steps = ['composer install', 'composer run lint', 'composer run analyse', 'artisan migrate', 'composer run test:coverage'];
    $positions = array_map(fn (string $step): int => (int) strpos($backend, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job backend.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

it('ejecuta el job frontend en su orden', function (): void {
    $frontend = workflowJob('frontend');

    $steps = ['npm ci', 'npm run lint', 'npm run types', 'npm run test', 'npm run build'];
    $positions = array_map(fn (string $step): int => (int) strpos($frontend, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job frontend.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

it('declara los servicios de MySQL 8 y Redis 7', function (): void {
    expect(workflowDirectives())
        ->toContain('image: mysql:8.0')
        ->toContain('image: redis:7-alpine');
});

it('liga la clave de caché al hash del lockfile', function (): void {
    expect(workflowDirectives())
        ->toContain("hashFiles('composer.lock')")
        ->toContain('cache-dependency-path: package-lock.json');
});

it('no despliega nada', function (): void {
    expect(workflowDirectives())
        ->not->toContain('docker push')
        ->not->toContain('deploy')
        ->not->toContain('ssh');
});

it('usa .env.ci y arranca en push a main y en cada PR', function (): void {
    expect(workflowDirectives())->toContain('cp .env.ci .env');
    expect(file_exists(base_path('.env.ci')))->toBeTrue();

    expect(workflowDirectives())
        ->toContain('push:')
        ->toContain('pull_request:');
});

it('no versiona ningún secreto en .env.ci', function (): void {
    $ci = (string) file_get_contents(base_path('.env.ci'));

    expect($ci)
        ->toMatch('/^GOOGLE_CLIENT_SECRET=\s*$/m')
        ->toMatch('/^VAPID_PRIVATE_KEY=\s*$/m')
        ->toMatch('/^PLATFORM_ADMIN_PASSWORD=\s*$/m');
});

it('deja ci.yml como único workflow', function (): void {
    $workflows = glob(base_path('.github/workflows/*.yml')) ?: [];

    expect(array_map('basename', $workflows))->toBe(['ci.yml']);
});
