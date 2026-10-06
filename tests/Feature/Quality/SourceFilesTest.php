<?php

declare(strict_types=1);

use App\Support\SourceFiles;

/**
 * `SourceFiles` (`T-058b`): recorrer el código sin perder archivos.
 *
 * En el montaje de desarrollo, los iteradores de directorios de SPL empiezan
 * a mitad de cualquier directorio que no cabe en un bloque de lectura, y
 * `tenancy:audit` llevaba un tiempo leyendo 18 de las 45 migraciones sin
 * decirlo. Una comprobación estructural que mira menos de lo que cree no
 * falla: se queda en verde. Por eso hay una sola forma de recorrer el código,
 * y esto vigila las dos mitades: que vea lo mismo que `glob()` y que nadie
 * vuelva a escribir un iterador de SPL.
 */

/**
 * Los usos de iteradores de directorio de SPL en ese código, sin contar
 * comentarios ni cadenas: solo nombres de clase escritos en el código.
 *
 * @return list<string>
 */
function splDirectoryIteratorsIn(string $code): array
{
    $forbidden = ['RecursiveDirectoryIterator', 'DirectoryIterator', 'FilesystemIterator', 'GlobIterator'];
    $found = [];

    foreach (token_get_all($code) as $token) {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $name = ltrim((string) substr((string) strrchr('\\'.$token[1], '\\'), 1), '\\');

        if (in_array($name, $forbidden, true)) {
            $found[] = $name;
        }
    }

    return array_values(array_unique($found));
}

it('lista lo mismo que glob en el directorio que destapó el problema', function (): void {
    $migrations = SourceFiles::in(database_path('migrations'));
    $globbed = array_map(fn (string $path): string => str_replace('\\', '/', $path), glob(database_path('migrations/*.php')) ?: []);
    sort($globbed);

    expect($migrations)->toBe($globbed);
    expect(count($migrations))->toBeGreaterThan(40);
});

it('recorre en profundidad, filtra por extensión y ordena', function (): void {
    $root = storage_path('framework/testing/source-files-'.uniqid());
    mkdir($root.'/b/c', 0777, true);

    foreach (['z.php', 'a.php', 'nota.txt', 'b/m.php', 'b/c/k.php', 'b/c/v.tsx'] as $file) {
        file_put_contents($root.'/'.$file, '');
    }

    try {
        expect(SourceFiles::in($root))->toBe([$root.'/a.php', $root.'/b/c/k.php', $root.'/b/m.php', $root.'/z.php']);
        expect(SourceFiles::in($root, ['tsx', 'txt']))->toBe([$root.'/b/c/v.tsx', $root.'/nota.txt']);
        expect(SourceFiles::in($root.'/no-existe'))->toBe([]);
    } finally {
        foreach (['z.php', 'a.php', 'nota.txt', 'b/m.php', 'b/c/k.php', 'b/c/v.tsx'] as $file) {
            unlink($root.'/'.$file);
        }

        rmdir($root.'/b/c');
        rmdir($root.'/b');
        rmdir($root);
    }
});

it('no deja recorrer el código con un iterador de directorios de SPL', function (): void {
    $offenders = [];

    foreach (['app', 'tests', 'database', 'routes', 'config'] as $directory) {
        foreach (SourceFiles::in(base_path($directory)) as $file) {
            $found = splDirectoryIteratorsIn((string) file_get_contents($file));

            if ($found !== []) {
                $offenders[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $file).': '.implode(', ', $found);
            }
        }
    }

    expect($offenders)->toBe([], "Iteradores de directorio de SPL fuera de SourceFiles (T-058b):\n".implode("\n", $offenders)
        ."\nEn el montaje de desarrollo se saltan archivos sin avisar. Usa App\\Support\\SourceFiles::in().");
});

/**
 * Y el detector detecta: un test que busca sobre un conjunto vacío pasa
 * también cuando está roto.
 */
it('caza las formas de escribir un iterador de directorios', function (string $code): void {
    expect(splDirectoryIteratorsIn("<?php\n".$code))->not->toBe([]);
})->with([
    'recursivo' => ['$i = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));'],
    'plano' => ['foreach (new DirectoryIterator($dir) as $f) {}'],
    'con barra' => ['$i = new \FilesystemIterator($dir);'],
    'glob' => ['$i = new GlobIterator("*.php");'],
]);

it('no confunde un comentario ni una cadena con un iterador', function (string $code): void {
    expect(splDirectoryIteratorsIn("<?php\n".$code))->toBe([]);
})->with([
    'comentario' => ['// antes usábamos RecursiveDirectoryIterator'],
    'docblock' => ["/** DirectoryIterator pierde archivos */\n\$x = 1;"],
    'cadena' => ['$names = [\'FilesystemIterator\'];'],
]);
