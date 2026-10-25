<?php

declare(strict_types=1);

use App\Support\SourceFiles;

/**
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
    $root = str_replace('\\', '/', storage_path('framework/testing/source-files-'.uniqid()));
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

    expect($offenders)->toBe([], "Iteradores de directorio de SPL fuera de SourceFiles:\n".implode("\n", $offenders)
        ."\nEn el montaje de desarrollo se saltan archivos sin avisar. Usa App\\Support\\SourceFiles::in().");
});

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
