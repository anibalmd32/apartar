<?php

declare(strict_types=1);

/**
 * Las reglas de `REQ-QAX-001` y `REQ-QAX-002` que no cubren ni Pint ni PHPStan
 * ni ESLint, comprobadas como tests para que corran en cada PR y no dependan
 * de que alguien se acuerde de mirarlas.
 */

/**
 * Las seis migraciones publicadas por paquetes quedan fuera del gate de
 * `strict_types`: no son código nuestro y volver a publicarlas revertiría el
 * cambio (decisión de T-003).
 *
 * @return list<string>
 */
function vendorPublishedMigrations(): array
{
    return [
        'create_media_table',
        'create_activity_log_table',
        'add_event_column_to_activity_log_table',
        'add_batch_uuid_column_to_activity_log_table',
        'create_push_subscriptions_table',
        'increase_push_subscriptions_endpoint_length',
    ];
}

/**
 * @param  string  $directory  ruta absoluta que se recorre en profundidad
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * `REQ-QAX-001` RB-9.
 */
it('declara strict_types en todo archivo de app/', function (): void {
    $offenders = [];

    foreach (phpFilesIn(app_path()) as $file) {
        if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1)')) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([], 'Sin strict_types: '.implode(', ', $offenders));
});

it('declara strict_types en las migraciones propias', function (): void {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
        $name = basename($migration, '.php');

        $isVendor = array_filter(
            vendorPublishedMigrations(),
            fn (string $published): bool => str_ends_with($name, $published),
        );

        if ($isVendor !== []) {
            continue;
        }

        if (! str_contains((string) file_get_contents($migration), 'declare(strict_types=1)')) {
            $offenders[] = basename($migration);
        }
    }

    expect($offenders)->toBe([], 'Migraciones sin strict_types: '.implode(', ', $offenders));
});

/**
 * `REQ-QAX-001` RB-8: prohibido `float`/`double` para importes en cualquier
 * capa, no solo en el esquema. Los casts de modelo son la otra puerta por
 * donde entra la coma flotante.
 */
it('no castea ninguna propiedad de modelo a float o double', function (): void {
    $offenders = [];

    foreach (phpFilesIn(app_path('Models')) as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match("/=>\s*'(float|double|real)'/i", $contents) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], 'Modelos con cast de coma flotante: '.implode(', ', $offenders));
});

/**
 * Las líneas de **código** de un archivo: sin blancos y sin comentarios.
 *
 * Es cómo cuenta `max-lines` de ESLint en el gate gemelo del frontend
 * (`REQ-QAX-002` RB-1, con `skipBlankLines` y `skipComments`), y desde `T-030`
 * también el del backend. El motivo está en `REQ-QAX-001` RB-3.
 */
function codeLinesIn(string $file): int
{
    $lines = 0;

    foreach (file($file) ?: [] as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*')) {
            continue;
        }

        $lines++;
    }

    return $lines;
}

/**
 * `REQ-QAX-001` RB-3 y SP-3: un modelo que crece por encima de 200 líneas de
 * código es la señal de que hay lógica de negocio que debería vivir en
 * `app/Domain`.
 *
 * **Cuenta código y no documentación** (`T-030`), igual que el gate gemelo del
 * frontend. Contando líneas en crudo, lo que se mide es cuánto se explica el
 * archivo, y este proyecto exige explicarlos: `Product` llegó a 200 con 87
 * líneas de código y 99 de comentario, de modo que el `scopePublished()` que
 * `REQ-CAT-007` **exige en ese mismo modelo** no cabía —ni siquiera sin una
 * sola línea de docblock—. Dos requisitos cerrados no pueden contradecirse, y
 * el que cede es el que estaba midiendo la cosa equivocada.
 */
it('mantiene los modelos por debajo de 200 líneas de código', function (): void {
    $offenders = [];

    foreach (phpFilesIn(app_path('Models')) as $file) {
        $lines = codeLinesIn($file);

        if ($lines > 200) {
            $offenders[] = basename($file).' ('.$lines.')';
        }
    }

    expect($offenders)->toBe([], 'Modelos por encima de 200 líneas de código: '.implode(', ', $offenders));
});

/**
 * `REQ-QAX-002` RB-3: `any` prohibido en todo `resources/js`, sin excepción de
 * directorio. La regla de ESLint lo cubre en CI; este test lo deja también en
 * el job de backend, que es el que siempre corre.
 */
it('no usa any en resources/js', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/(:\s*any\b|<any>|\bas\s+any\b|Array<any>)/', $contents) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], 'Con `any`: '.implode(', ', $offenders));
});

/**
 * `REQ-QAX-001` RB-5 y `REQ-QAX-002` RB-5: `components/ui` es de los
 * envoltorios de daisyUI y de nadie más.
 *
 * Hasta `T-010` el directorio estaba vacío y bastaba comprobar eso. Con los
 * primeros átomos dentro, lo que hay que sostener es la regla de verdad: que
 * ninguno se apoye en el shadcn heredado de `components/vendor` ni en Radix.
 * Un envoltorio daisyUI que por dentro monta un primitivo de Radix no es un
 * envoltorio, es la misma dependencia con otro nombre, y el día que
 * `vendor/` se borre se lo llevará por delante.
 */
it('mantiene components/ui libre de primitivos de shadcn', function (): void {
    $offenders = [];

    foreach (glob(resource_path('js/components/ui/*.tsx')) ?: [] as $atom) {
        $contents = (string) file_get_contents($atom);

        if (preg_match("#from\s+'[^']*(@radix-ui|components/vendor)#", $contents) === 1) {
            $offenders[] = basename($atom);
        }
    }

    expect($offenders)->toBe([], 'Átomos de daisyUI apoyados en shadcn: '.implode(', ', $offenders));
    expect(is_dir(resource_path('js/components/vendor')))->toBeTrue();
});

/**
 * Y la otra mitad de la misma regla: las páginas que escribimos nosotras no
 * importan de `components/vendor`. Las heredadas del starter kit siguen
 * haciéndolo y se migran con su tarea (`T-012`); lo que no puede pasar es que
 * una página nueva nazca atada al directorio que está en retirada.
 */
it('escribe las páginas propias sin los primitivos heredados', function (): void {
    $offenders = [];

    foreach (glob(resource_path('js/pages/marketing/*.tsx')) ?: [] as $page) {
        // Se miran los `import`, no las menciones: explicar en un comentario
        // por qué no se usa `components/vendor` no es usarlo.
        if (preg_match("#from\s+'[^']*components/vendor#", (string) file_get_contents($page)) === 1) {
            $offenders[] = basename($page);
        }
    }

    expect($offenders)->toBe([], 'Páginas propias con primitivos heredados: '.implode(', ', $offenders));
});
