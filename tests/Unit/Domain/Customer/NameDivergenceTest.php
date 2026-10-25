<?php

declare(strict_types=1);

use App\Domain\Customer\NameDivergence;

dataset('la misma persona escrita de otra forma', [
    'tildes y mayúsculas' => ['María González', 'MARIA GONZALEZ'],
    'segundo apellido' => ['María González', 'María González Pérez'],
    'segundo nombre' => ['José Pérez', 'José Luis Pérez'],
    'solo el apellido en común' => ['María González', 'Carmen González'],
    'errata en una palabra larga' => ['María González', 'Maria Gonzales'],
    'inicial en vez del nombre' => ['J. Pérez', 'José Pérez'],
    'partículas' => ['Ana de la Rosa', 'Ana Rosa'],
    'espacios y signos' => ['  maría   gonzález-pérez ', 'María González Pérez'],
    'razón social con otra forma jurídica' => ['Inversiones El Tornillo C.A.', 'Inversiones El Tornillo, S.A.'],
]);

it('no ve conflicto en la misma persona escrita de otra forma', function (string $before, string $after): void {
    expect(NameDivergence::isRadical($before, $after))->toBeFalse();
})->with('la misma persona escrita de otra forma');

dataset('otra persona', [
    'nombre y apellidos distintos' => ['María González', 'Pedro Ramírez'],
    'una sola palabra cada uno' => ['Carmen', 'Luisa'],
    'otra empresa' => ['Ferretería Los Andes', 'Distribuidora Caracas'],
    'palabras cortas que difieren en una letra' => ['Ana Rosa', 'Eva Rita'],
]);

it('ve conflicto cuando no comparten ni una palabra', function (string $before, string $after): void {
    expect(NameDivergence::isRadical($before, $after))->toBeTrue();
})->with('otra persona');

it('no ve conflicto cuando uno de los dos no deja palabras que comparar', function (string $before, string $after): void {
    expect(NameDivergence::isRadical($before, $after))->toBeFalse();
})->with([
    'solo iniciales' => ['J. P.', 'María González'],
    'vacío' => ['', 'María González'],
]);
