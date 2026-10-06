<?php

declare(strict_types=1);

use App\Support\SourceFiles;

/**
 * Umbral de cobertura del dominio (`REQ-QAX-003` RB-1 y SP-4).
 *
 * El umbral son dos piezas que tienen que seguir de acuerdo: el filtro de
 * `phpunit.xml`, que decide **qué** se mide, y el `--min` del script, que
 * decide **cuánto** hace falta. Separarlas es fácil y el resultado no falla:
 * mide otra cosa, o no mide nada, y pasa en verde igual.
 *
 * **Estado a día de hoy:** `app/Domain` y `app/Actions` no contienen todavía
 * ningún archivo PHP, así que PHPUnit avisa de que el filtro no casa con nada
 * y no procesa cobertura; el `--min=85` no puede fallar. El umbral empieza a
 * morder con la primera clase de dominio (T-017). Lo que estos tests
 * garantizan mientras tanto es que el cableado no se rompa por el camino.
 */
function phpunitConfiguration(): SimpleXMLElement
{
    $xml = simplexml_load_file(base_path('phpunit.xml'));

    expect($xml)->not->toBeFalse();

    /** @var SimpleXMLElement $xml */
    return $xml;
}

/**
 * RB-1: sin umbral global, para no premiar tests de relleno en las capas
 * triviales. Se mide donde está el dinero.
 */
it('mide la cobertura solo sobre app/Domain y app/Actions', function (): void {
    $directories = [];

    foreach (phpunitConfiguration()->source->include->directory as $directory) {
        $directories[] = (string) $directory;
    }

    expect($directories)->toEqualCanonicalizing(['app/Domain', 'app/Actions']);
});

it('exige el 85 % en el script que ejecuta CI', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect(implode(' ', $composer['scripts']['test:coverage']))
        ->toContain('--coverage')
        ->toContain('--min=85');
});

/**
 * Los dos directorios existen desde T-003 justamente para esto: un filtro
 * apuntando a una ruta inexistente no falla, simplemente no mide.
 */
it('mantiene existentes los directorios que mide', function (string $directory): void {
    expect(is_dir(base_path($directory)))->toBeTrue();
})->with(['app/Domain', 'app/Actions']);

/**
 * La red de seguridad de SP-4: en cuanto exista la primera clase de dominio,
 * la suite tiene que tener también su primer test unitario. Sin esto, T-017
 * podría añadir `Money` y descubrir el umbral roto varias tareas más tarde.
 */
it('acompaña cada clase de dominio con su test unitario', function (): void {
    $domainClasses = [];

    // Por `SourceFiles`: un iterador de SPL pierde archivos en el montaje de
    // desarrollo, y aquí eso es un test que no se exige (`T-058b`).
    foreach (['Domain', 'Actions'] as $layer) {
        foreach (SourceFiles::in(app_path($layer)) as $file) {
            $domainClasses[] = basename($file, '.php');
        }
    }

    if ($domainClasses === []) {
        expect($domainClasses)->toBe([]);

        return;
    }

    // Se busca en toda la suite y no solo en `tests/Unit`: el dominio puro
    // se prueba sin framework (`REQ-QAX-003` RB-2), pero piezas como
    // `BelongsToOrganization` o su global scope solo se pueden ejercitar
    // contra una base de datos real. Lo que la regla exige es que **cada
    // clase tenga su test dedicado**, no en qué suite vive.
    $tests = array_map(fn (string $file): string => basename($file, '.php'), SourceFiles::in(base_path('tests')));

    $uncovered = array_values(array_filter(
        $domainClasses,
        fn (string $class): bool => ! in_array($class.'Test', $tests, true),
    ));

    expect($uncovered)->toBe([], 'Sin test unitario: '.implode(', ', $uncovered));
});
