<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Los tests de concurrencia corren **en serie**, y este es el mecanismo que
 * impide olvidarlo (`T-003b`, `REQ-QAX-003`).
 *
 * ## Por qué hace falta un test para una convención de carpetas
 *
 * Desde `T-003b` la suite corre en paralelo, con una base de datos por
 * proceso. Un test de concurrencia no cabe ahí: necesita **dos conexiones
 * sobre filas confirmadas** —un bloqueo entre conexiones no es observable
 * dentro de la transacción de `RefreshDatabase`—, así que confirma de verdad y
 * después barre a mano lo que sembró.
 *
 * Escrito en cualquier otra carpeta, ese archivo entra en la ronda paralela y
 * **el fallo no aparece en él**: aparece en otro archivo, en otro proceso, un
 * día de cada cinco. Es la forma más cara que tiene una suite de romperse, y
 * es exactamente el `deadlock` intermitente al sembrar planes que se arrastraba
 * desde `T-030`.
 *
 * Un comentario en `phpunit.xml` no basta para eso, porque quien escribe el
 * test siguiente no lo está leyendo. Esto sí se lo dice, en el momento en que
 * lo escribe y con el sitio al que moverlo.
 *
 * ## Qué cuenta como test de concurrencia
 *
 * Dos señales, y con una basta: el nombre del archivo termina en
 * `ConcurrencyTest`, o el archivo abre una segunda conexión con
 * `database.connections.mysql_rival`, que es la receta que usan los dos que
 * ya existen. La segunda es la que atrapa a un archivo bien intencionado con
 * otro nombre.
 */
function testFilesOutsideConcurrency(): Finder
{
    return Finder::create()
        ->files()
        ->in(base_path('tests'))
        ->notPath('Feature/Concurrency')
        // Este mismo archivo queda fuera, y no es una trampa: **nombra** la
        // receta para poder buscarla, así que buscarse a sí mismo es la única
        // forma garantizada de dar un falso positivo.
        ->notName('ConcurrencyTestsAreIsolatedTest.php')
        ->name('*.php');
}

it('mantiene los tests de concurrencia en su propia carpeta', function (): void {
    $misplaced = [];

    foreach (testFilesOutsideConcurrency() as $file) {
        $name = $file->getBasename('.php');

        // **Sin comentarios.** Media docena de tests explican en su docblock
        // que la concurrencia se prueba en otro sitio —`LastOwnerGuardTest` y
        // `LimitEnforcerTest`, entre otros—, y un `grep` sobre el texto crudo
        // confundiría la explicación con la infracción. Es la misma cautela
        // que usan los escaneos de fuente de `T-032`.
        $code = php_strip_whitespace($file->getRealPath());

        if (str_ends_with($name, 'ConcurrencyTest') || str_contains($code, 'mysql_rival')) {
            $misplaced[] = $file->getRelativePathname();
        }
    }

    expect($misplaced)->toBe([], sprintf(
        'Estos tests abren una segunda conexión o se llaman «…ConcurrencyTest» y están fuera de '
        .'tests/Feature/Concurrency: %s. Ahí corren en paralelo contra una base compartida con nadie, y lo que '
        .'rompen es otro archivo. Muévelos a tests/Feature/Concurrency, que es el testsuite que corre en serie.',
        implode(', ', $misplaced),
    ));
});

/**
 * Y la otra mitad: la carpeta **no puede quedarse vacía sin que se note**.
 *
 * Una aserción sobre un conjunto que hoy está vacío pasa también cuando el
 * mecanismo está roto — si alguien borrara la carpeta, el test de arriba
 * seguiría en verde sobre cero archivos y el *testsuite* `Concurrency` correría
 * sobre nada. `failOnEmptyTestSuite` lo cazaría al ejecutarlo; esto lo caza
 * antes y dice por qué.
 */
it('mantiene poblado el testsuite que corre en serie', function (): void {
    $files = Finder::create()->files()->in(base_path('tests/Feature/Concurrency'))->name('*Test.php');

    expect(iterator_count($files))->toBeGreaterThanOrEqual(2);
});

/**
 * El *testsuite* existe y el `Feature` lo excluye.
 *
 * Son las dos mitades de la misma decisión y se rompen por separado: con el
 * *testsuite* declarado pero sin la exclusión, los dos archivos correrían
 * **también** en la ronda paralela, que es justo lo que se vino a evitar; y
 * viceversa, quedarían sin correr en ninguna.
 */
it('declara el testsuite en serie y lo excluye del paralelo', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<testsuite name="Concurrency">')
        ->toContain('<directory>tests/Feature/Concurrency</directory>')
        ->toContain('<exclude>tests/Feature/Concurrency</exclude>');
});
