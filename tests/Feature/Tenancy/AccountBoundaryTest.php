<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Frontera cuenta ↔ organización (`REQ-QAX-008` RB-6).
 *
 * Es la parte más sutil del modelo. Una `Account` es global: la misma persona
 * sigue catálogos de tres negocios con una sola cuenta. Los tres negocios ven a
 * *su* cliente —su ficha, sus apartados, su cobranza— y ninguno puede saber que
 * los otros dos existen. Si un backoffice llegara a ver el `account_id`, con él
 * podría cruzar clientes entre negocios: no es una fuga de datos de otra
 * organización, es una fuga sobre la persona.
 *
 * `Account` llega en `T-073` y `Customer` en H3, así que la mitad de RB-6 que
 * habla de consultas no se puede comprobar todavía. La otra mitad —**ningún
 * `Resource`, respuesta JSON ni exportación del backoffice expone
 * `account_id`**— sí es afirmable hoy como test de arquitectura, y lo es
 * precisamente porque hoy no hay nada que la incumpla: el conjunto está vacío y
 * crece solo. El primer `Resource` que exponga la columna hará fallar este
 * archivo sin que nadie tenga que acordarse de volver.
 *
 * Se mira dónde se **expone**, no dónde se usa: la columna es legítima en la
 * migración, en el modelo y en las consultas que resuelven la ficha. Lo que no
 * puede es salir por la puerta.
 */
final class AccountBoundaryTest extends TestCase
{
    /**
     * Columna que no puede cruzar la frontera (`REQ-ACC-007` RB-5).
     */
    private const FORBIDDEN = 'account_id';

    /**
     * RB-6 · ningún `Resource` la expone.
     */
    public function test_no_api_resource_exposes_the_account_id(): void
    {
        $this->assertSame([], $this->offendersAmong(
            $this->phpFilesIn(app_path('Http/Resources')),
        ));
    }

    /**
     * Ni ninguna exportación, que es la vía de SP-9: el `account_id` colado en
     * una columna de un Excel que el negocio descarga y conserva.
     */
    public function test_no_export_exposes_the_account_id(): void
    {
        $exports = array_filter(
            $this->phpFilesIn(app_path()),
            fn (string $file): bool => str_contains(basename($file), 'Export')
                || str_contains(basename($file), 'Sheet'),
        );

        $this->assertSame([], $this->offendersAmong(array_values($exports)));
    }

    /**
     * Ni ningún `Resource` suelto fuera de `app/Http/Resources`.
     */
    public function test_no_resource_outside_its_directory_exposes_the_account_id(): void
    {
        $resources = array_filter(
            $this->phpFilesIn(app_path()),
            fn (string $file): bool => str_ends_with($file, 'Resource.php'),
        );

        $this->assertSame([], $this->offendersAmong(array_values($resources)));
    }

    /**
     * Y la puerta por la que de verdad sale todo en este proyecto: los tipos de
     * las props de Inertia.
     *
     * El backoffice no consume una API REST, consume props tipadas. Un
     * `account_id` en `resources/js/types` significa que la columna viajó al
     * navegador, y ahí ya está expuesta aunque ninguna pantalla la pinte.
     */
    public function test_no_frontend_type_declares_the_account_id(): void
    {
        $offenders = [];

        foreach ($this->frontendFilesIn(resource_path('js')) as $file) {
            if (str_contains((string) file_get_contents($file), self::FORBIDDEN)) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, 'account_id declarado en el frontend: '.implode(', ', $offenders));
    }

    /**
     * Y la prueba de que las cuatro aserciones anteriores no pasan por estar
     * mirando un conjunto vacío con los ojos cerrados: un `Resource` que expone
     * la columna se detecta, y una mención en un comentario no.
     */
    public function test_the_check_notices_an_exposed_account_id(): void
    {
        $exposed = $this->temporaryFile(<<<'PHP'
            <?php

            class CustomerResource
            {
                public function toArray(): array
                {
                    return ['id' => 1, 'account_id' => 7];
                }
            }
            PHP);

        $documented = $this->temporaryFile(<<<'PHP'
            <?php

            class SafeResource
            {
                /** Nunca se expone account_id: cruzaría la frontera de REQ-ACC-007 RB-5. */
                public function toArray(): array
                {
                    return ['id' => 1];
                }
            }
            PHP);

        try {
            $this->assertCount(1, $this->offendersAmong([$exposed, $documented]));
        } finally {
            unlink($exposed);
            unlink($documented);
        }
    }

    /**
     * Archivos que nombran la columna, con el comentario descontado: explicar
     * por qué `account_id` no puede salir no es exponerlo.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function offendersAmong(array $files): array
    {
        $offenders = [];

        foreach ($files as $file) {
            if (str_contains($this->codeOf($file), self::FORBIDDEN)) {
                $offenders[] = $this->relative($file);
            }
        }

        return $offenders;
    }

    /**
     * Código del archivo sin comentarios.
     */
    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $token[1];
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        return $this->filesIn($directory, ['php']);
    }

    /**
     * @return list<string>
     */
    private function frontendFilesIn(string $directory): array
    {
        return $this->filesIn($directory, ['ts', 'tsx']);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesIn(string $directory, array $extensions): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(str_replace('\\', '/', base_path()).'/', '', $file);
    }

    /**
     * Archivo PHP suelto para probar el detector sin tocar el repositorio.
     */
    private function temporaryFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/account-boundary-'.bin2hex(random_bytes(6)).'.php';

        file_put_contents($path, $contents."\n");

        return $path;
    }
}
