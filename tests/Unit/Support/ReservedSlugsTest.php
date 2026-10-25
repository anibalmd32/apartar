<?php

declare(strict_types=1);

use App\Support\ReservedSlugs;

it('reserva los doce segmentos de la tabla de rutas', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with([
    'registro', 'precios', 'backoffice', 'plataforma', 'c', 'mi-cuenta',
    'api', 'archivos', 'terminos', 'privacidad', 'invitacion', 'cuenta',
]);

it('reserva también los caminos que ocupa el framework', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with(['up', 'storage']);

it('deja libre cualquier otro nombre', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeFalse();
})->with(['ferreteria-el-tornillo', 'precios-de-ana', 'mi-tienda', 'apartar']);

it('reconoce el reservado venga como venga escrito', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with(['Precios', 'PRECIOS', '  precios  ', 'Mi Cuenta']);

it('mantiene la lista corta y consultable', function (): void {
    expect(ReservedSlugs::all())->toHaveCount(14);
});

it('excluye de la ruta de la vitrina todos los reservados y nada más', function (): void {
    $pattern = '#^'.ReservedSlugs::routePattern().'$#';

    foreach (ReservedSlugs::all() as $slug) {
        expect(preg_match($pattern, $slug))->toBe(0, $slug);
    }

    expect(preg_match($pattern, 'ferreteria-el-tornillo'))->toBe(1)
        ->and(preg_match($pattern, 'precios-bajos'))->toBe(1)
        ->and(preg_match($pattern, 'cuentas'))->toBe(1)
        ->and(preg_match($pattern, 'Precios'))->toBe(0);
});
