<?php

declare(strict_types=1);

use App\Support\SourceFiles;

/**
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
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    return SourceFiles::in($directory);
}

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

it('no usa any en resources/js', function (): void {
    $offenders = [];

    foreach (SourceFiles::in(resource_path('js'), ['ts', 'tsx']) as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match('/(:\s*any\b|<any>|\bas\s+any\b|Array<any>)/', $contents) === 1) {
            $offenders[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $file);
        }
    }

    expect($offenders)->toBe([], 'Con `any`: '.implode(', ', $offenders));
});

it('mantiene components/ui libre de primitivos de shadcn', function (): void {
    $offenders = [];

    foreach (glob(resource_path('js/components/ui/*.tsx')) ?: [] as $atom) {
        $contents = (string) file_get_contents($atom);

        if (preg_match("#from\s+'[^']*(@radix-ui|components/vendor)#", $contents) === 1) {
            $offenders[] = basename($atom);
        }
    }

    expect($offenders)->toBe([], 'Átomos de daisyUI apoyados en shadcn: '.implode(', ', $offenders));
    expect(is_dir(resource_path('js/components/vendor')))->toBeFalse();
});

it('escribe las páginas propias sin los primitivos heredados', function (): void {
    $offenders = [];

    foreach (glob(resource_path('js/pages/marketing/*.tsx')) ?: [] as $page) {
        if (preg_match("#from\s+'[^']*components/vendor#", (string) file_get_contents($page)) === 1) {
            $offenders[] = basename($page);
        }
    }

    expect($offenders)->toBe([], 'Páginas propias con primitivos heredados: '.implode(', ', $offenders));
});
