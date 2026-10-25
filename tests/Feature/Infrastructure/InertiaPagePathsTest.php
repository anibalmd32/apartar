<?php

declare(strict_types=1);

function directoryExistsWithExactCase(string $path): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $base = str_replace('\\', '/', base_path());

    if (! str_starts_with($path, $base.'/')) {
        return false;
    }

    $current = $base;

    foreach (explode('/', substr($path, strlen($base) + 1)) as $segment) {
        $entries = is_dir($current) ? (scandir($current) ?: []) : [];

        if (! in_array($segment, $entries, true)) {
            return false;
        }

        $current .= '/'.$segment;
    }

    return is_dir($current);
}

it('busca las páginas de Inertia en una carpeta que existe con esas mismas mayúsculas', function (string $key): void {
    $paths = config($key);

    expect($paths)->toBeArray()->not->toBeEmpty();

    foreach ($paths as $path) {
        expect(directoryExistsWithExactCase($path))->toBeTrue("No existe tal cual en disco: {$path}");
    }
})->with(['inertia.page_paths', 'inertia.testing.page_paths']);
