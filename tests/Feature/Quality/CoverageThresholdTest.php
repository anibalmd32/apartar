<?php

declare(strict_types=1);

use App\Support\SourceFiles;

function phpunitConfiguration(): SimpleXMLElement
{
    $xml = simplexml_load_file(base_path('phpunit.xml'));

    expect($xml)->not->toBeFalse();

    /** @var SimpleXMLElement $xml */
    return $xml;
}

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

it('mantiene existentes los directorios que mide', function (string $directory): void {
    expect(is_dir(base_path($directory)))->toBeTrue();
})->with(['app/Domain', 'app/Actions']);

it('acompaña cada clase de dominio con su test unitario', function (): void {
    $domainClasses = [];

    foreach (['Domain', 'Actions'] as $layer) {
        foreach (SourceFiles::in(app_path($layer)) as $file) {
            $domainClasses[] = basename($file, '.php');
        }
    }

    if ($domainClasses === []) {
        expect($domainClasses)->toBe([]);

        return;
    }

    $tests = array_map(fn (string $file): string => basename($file, '.php'), SourceFiles::in(base_path('tests')));

    $uncovered = array_values(array_filter(
        $domainClasses,
        fn (string $class): bool => ! in_array($class.'Test', $tests, true),
    ));

    expect($uncovered)->toBe([], 'Sin test unitario: '.implode(', ', $uncovered));
});
