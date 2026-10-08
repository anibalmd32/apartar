<?php

declare(strict_types=1);

use App\Models\Layaway;
use App\Support\ModelState;
use Symfony\Component\Finder\Finder;

/**
 * Devolver a quien llamó el modelo al día, sin el saldo viejo encima
 * (`ModelState::adopt()`, `T-064`).
 *
 * `setRawAttributes(..., sync: true)` vuelve a escribir sobre los atributos
 * nuevos lo que la caché de los casts de clase guardaba de una lectura
 * anterior: un `paid_cents` leído antes de recalcular sobrevivía al recálculo.
 * Este archivo prueba el arreglo y prohíbe la forma rota en `app/`.
 */
it('deja en el modelo los atributos nuevos aunque ya se hubieran leído', function (): void {
    $layaway = new Layaway;
    $layaway->setRawAttributes(['paid_cents' => 4_000, 'remaining_cents' => 16_000], sync: true);
    expect($layaway->paid_cents->cents)->toBe(4_000);

    ModelState::adopt($layaway, ['paid_cents' => 5_000, 'remaining_cents' => 15_000]);

    expect([$layaway->paid_cents->cents, $layaway->remaining_cents->cents])->toBe([5_000, 15_000])
        ->and($layaway->isDirty())->toBeFalse();
});

it('es justo lo que la forma con sync rompe', function (): void {
    $layaway = new Layaway;
    $layaway->setRawAttributes(['paid_cents' => 4_000], sync: true);
    expect($layaway->paid_cents->cents)->toBe(4_000);

    $layaway->setRawAttributes(['paid_cents' => 5_000], sync: true);

    // Si Laravel lo arregla algún día, este test lo dirá y la regla de abajo
    // podrá relajarse; mientras tanto, la forma rota sigue rota.
    expect($layaway->paid_cents->cents)->toBe(4_000);
});

it('no usa setRawAttributes con sync en app/', function (): void {
    $offenders = [];
    $root = (string) realpath(base_path());

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getRealPath())) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }

        if (preg_match('/setRawAttributes\([^;]*(sync:\s*true|,\s*true\s*\))/', $code) === 1) {
            $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getRealPath());
        }
    }

    expect($offenders)->toBe([], 'Usa ModelState::adopt(): con sync, la caché de casts deja el valor viejo.');
});
