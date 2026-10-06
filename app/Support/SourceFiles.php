<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los archivos de un directorio, en profundidad y en orden estable: la única
 * forma de recorrer el código que usan `tenancy:audit` y los tests de
 * arquitectura (`T-058b`).
 *
 * ## Por qué no `RecursiveDirectoryIterator`
 *
 * Porque en el entorno de desarrollo **pierde archivos sin decir nada**. El
 * repo vive en Windows y Docker Desktop lo monta en el contenedor (musl); en
 * ese montaje, `rewinddir()` no vuelve al principio de un directorio que no se
 * leyó entero, y los iteradores de SPL rebobinan al empezar el `foreach`
 * después de haber leído ya la primera entrada. En un directorio cuyas
 * entradas caben en un bloque de lectura no pasa nada; en uno más grande, el
 * recorrido empieza **a mitad** y se salta todo lo anterior. Se midió en
 * `T-059`: `database/migrations` tenía 45 archivos y el iterador veía 18, así
 * que `tenancy:audit` llevaba un tiempo sin leer 27 migraciones —entre ellas
 * las de apartados, pagos y comprobantes— y respondía «sin violaciones».
 *
 * El síntoma es el peor posible para una comprobación estructural: no falla,
 * **se queda en verde con menos que mirar**. Así que esta clase lista con
 * `scandir()`, que lee el directorio de una vez y no rebobina, y
 * `SourceFilesTest` impide que vuelva a aparecer un iterador de SPL en `app/`,
 * `tests/` o `database/`.
 */
final class SourceFiles
{
    /**
     * Las rutas, con `/`, de los archivos de `$directory` y sus subdirectorios
     * cuya extensión esté en `$extensions`, ordenadas.
     *
     * Un directorio que no existe no tiene archivos. Los enlaces simbólicos a
     * directorios no se siguen: el código del proyecto no los tiene, y
     * seguirlos podría dar vueltas para siempre.
     *
     * @param  list<string>  $extensions  sin punto, como devuelve `pathinfo()`
     * @return list<string>
     */
    public static function in(string $directory, array $extensions = ['php']): array
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $pending = [$directory];

        while ($pending !== []) {
            $current = array_pop($pending);
            $entries = scandir($current);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.'/'.$entry;

                if (is_dir($path)) {
                    if (! is_link($path)) {
                        $pending[] = $path;
                    }

                    continue;
                }

                if (in_array(pathinfo($entry, PATHINFO_EXTENSION), $extensions, true)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }
}
