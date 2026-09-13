<?php

declare(strict_types=1);

use App\Support\ReservedSlugs;

/**
 * La lista cerrada de [05 §7.2] más los dos caminos del framework.
 *
 * Lo que hace daño no es el `404`: la vitrina vive en `/{organization:slug}`,
 * un comodín que **cede ante toda ruta literal**, así que un negocio llamado
 * «Precios» vería su web mostrando la página de precios de la plataforma sin
 * un solo error en ningún log.
 */
it('reserva los doce segmentos de la tabla de rutas', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with([
    'registro', 'precios', 'backoffice', 'plataforma', 'c', 'mi-cuenta',
    'api', 'archivos', 'terminos', 'privacidad', 'invitacion', 'cuenta',
]);

/**
 * Y los dos que registra Laravel por su cuenta y que no están en la tabla de
 * rutas: el *health check* y el disco público.
 */
it('reserva también los caminos que ocupa el framework', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with(['up', 'storage']);

it('deja libre cualquier otro nombre', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeFalse();
})->with(['ferreteria-el-tornillo', 'precios-de-ana', 'mi-tienda', 'apartar']);

/**
 * Compara sobre el slug normalizado: `Precios`, `PRECIOS` y ` precios ` son el
 * mismo segmento de URL, y dejar pasar uno de los tres sería dejarlos pasar
 * todos.
 */
it('reconoce el reservado venga como venga escrito', function (string $slug): void {
    expect(ReservedSlugs::contains($slug))->toBeTrue();
})->with(['Precios', 'PRECIOS', '  precios  ', 'Mi Cuenta']);

it('mantiene la lista corta y consultable', function (): void {
    expect(ReservedSlugs::all())->toHaveCount(14);
});
