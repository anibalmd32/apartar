<?php

declare(strict_types=1);

/**
 * El pipeline de `REQ-QAX-004`, comprobado como cualquier otro artefacto.
 *
 * Un workflow roto se descubre tarde y siempre en el peor momento; estas
 * aserciones lo leen como texto porque es lo único disponible sin ejecutar
 * GitHub Actions, y cubren justo lo que el gate del requisito enumera.
 */
function workflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
}

/**
 * Texto del workflow sin comentarios: lo que GitHub Actions interpreta.
 */
function workflowDirectives(): string
{
    $lines = array_filter(
        preg_split('/\R/', workflow()) ?: [],
        fn (string $line): bool => ! str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

/**
 * Bloque crudo de un job, desde su clave hasta el siguiente job.
 */
function workflowJob(string $name): string
{
    $directives = workflowDirectives();
    $start = strpos($directives, "\n  {$name}:\n");

    expect($start)->not->toBeFalse("El job {$name} no está declarado.");

    $rest = substr($directives, (int) $start + 1);
    $next = preg_split('/\n  [a-z][a-z0-9_-]*:\n/', $rest, 2);

    return $next[0];
}

/**
 * RB-2: los tres jobs, y los tres tienen que pasar para poder mezclar.
 */
it('declara los tres jobs', function (string $job): void {
    expect(workflowDirectives())->toContain("\n  {$job}:\n");
})->with(['backend', 'frontend', 'tenancy']);

/**
 * RB-2: en paralelo. Un `needs` entre ellos los pondría en fila y retrasaría
 * el informe del que falla.
 */
it('corre los tres jobs en paralelo', function (): void {
    expect(workflowDirectives())->not->toContain('needs:');
});

/**
 * RB-4b: `tenancy` es un job propio y separado de `backend` para que su fallo
 * sea inequívoco y no quede sepultado entre cientos de tests de negocio.
 */
it('mantiene tenancy independiente de backend', function (): void {
    expect(workflowJob('tenancy'))
        ->toContain('composer run tenancy:suite')
        ->not->toContain('needs:');

    expect(workflowJob('backend'))->not->toContain('--testsuite=Tenancy');
});

/**
 * `REQ-QAX-008` RB-4b: el orden de los pasos del job de aislamiento. La
 * auditoría estática va **antes** que la suite: es de segundos y su fallo se
 * lee de un vistazo, así que esperar a la suite para enterarse de que un modelo
 * nuevo no lleva el trait solo alarga el ciclo.
 */
it('ejecuta el job tenancy en el orden de RB-4b', function (): void {
    $tenancy = workflowJob('tenancy');

    $steps = ['artisan migrate', 'composer run tenancy:audit', 'composer run tenancy:suite'];
    $positions = array_map(fn (string $step): int => (int) strpos($tenancy, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job tenancy.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

/**
 * RB-5: un fallo bloquea la mezcla. `continue-on-error` convertiría cualquier
 * gate en decorativo.
 */
it('no marca ningún paso como continuar en caso de error', function (): void {
    expect(workflowDirectives())
        ->not->toContain('continue-on-error')
        ->not->toContain('if: always()');
});

/**
 * RB-3: el orden de los pasos del job backend.
 */
it('ejecuta el job backend en el orden de RB-3', function (): void {
    $backend = workflowJob('backend');

    $steps = ['composer install', 'composer run lint', 'composer run analyse', 'artisan migrate', 'composer run test:coverage'];
    $positions = array_map(fn (string $step): int => (int) strpos($backend, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job backend.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

/**
 * RB-4: el build de producción entra en CI a propósito (SP-4): una importación
 * dinámica rota solo aparece ahí.
 */
it('ejecuta el job frontend en el orden de RB-4', function (): void {
    $frontend = workflowJob('frontend');

    $steps = ['npm ci', 'npm run lint', 'npm run types', 'npm run test', 'npm run build'];
    $positions = array_map(fn (string $step): int => (int) strpos($frontend, $step), $steps);

    expect($positions)->not->toContain(0, 'Falta un paso del job frontend.');
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

/**
 * Contratos de datos del requisito: MySQL 8 y Redis 7 como servicios.
 */
it('declara los servicios de MySQL 8 y Redis 7', function (): void {
    expect(workflowDirectives())
        ->toContain('image: mysql:8.0')
        ->toContain('image: redis:7-alpine');
});

/**
 * SP-2: una caché corrupta se invalida sola al cambiar el lockfile.
 */
it('liga la clave de caché al hash del lockfile', function (): void {
    expect(workflowDirectives())
        ->toContain("hashFiles('composer.lock')")
        ->toContain('cache-dependency-path: package-lock.json');
});

/**
 * RB-6: el pipeline verifica, no despliega (`ASU-INF-06`).
 */
it('no despliega nada', function (): void {
    expect(workflowDirectives())
        ->not->toContain('docker push')
        ->not->toContain('deploy')
        ->not->toContain('ssh');
});

/**
 * RB-7 y RB-1.
 */
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

/**
 * `REQ-QAX-006` RB-7 y SP-2: sin la lista de requisitos la revisión no empieza.
 */
it('pide en la plantilla de PR los requisitos que cierra y la documentación', function (): void {
    $template = (string) file_get_contents(base_path('.github/pull_request_template.md'));

    expect($template)
        ->toContain('Requisitos que cierra')
        ->toContain('REQ-QAX-006')
        ->toContain('PHPDoc')
        ->toContain('TSDoc')
        ->toContain('Anti-`SHALLOW`');
});

/**
 * Decisión de T-003: los workflows del starter kit se reemplazan por uno solo.
 */
it('deja ci.yml como único workflow', function (): void {
    $workflows = glob(base_path('.github/workflows/*.yml')) ?: [];

    expect(array_map('basename', $workflows))->toBe(['ci.yml']);
});
