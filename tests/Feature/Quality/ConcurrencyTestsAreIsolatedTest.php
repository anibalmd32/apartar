<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

function testFilesOutsideConcurrency(): Finder
{
    return Finder::create()
        ->files()
        ->in(base_path('tests'))
        ->notPath('Feature/Concurrency')
        ->notName('ConcurrencyTestsAreIsolatedTest.php')
        ->name('*.php');
}

it('mantiene los tests de concurrencia en su propia carpeta', function (): void {
    $misplaced = [];

    foreach (testFilesOutsideConcurrency() as $file) {
        $name = $file->getBasename('.php');

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

it('mantiene poblado el testsuite que corre en serie', function (): void {
    $files = Finder::create()->files()->in(base_path('tests/Feature/Concurrency'))->name('*Test.php');

    expect(iterator_count($files))->toBeGreaterThanOrEqual(2);
});

it('declara el testsuite en serie y lo excluye del paralelo', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<testsuite name="Concurrency">')
        ->toContain('<directory>tests/Feature/Concurrency</directory>')
        ->toContain('<exclude>tests/Feature/Concurrency</exclude>');
});
