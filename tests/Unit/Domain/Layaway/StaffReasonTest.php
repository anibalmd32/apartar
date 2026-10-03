<?php

declare(strict_types=1);

use App\Domain\Layaway\StaffReason;

/**
 * El motivo de una cancelación o una reprogramación (`REQ-LAY-009` RB-2,
 * `REQ-LAY-011` RB-4): de 10 a 500 caracteres.
 */
it('acepta los dos bordes y recorta los espacios', function (): void {
    expect((new StaffReason('  '.str_repeat('a', 10).'  '))->text)->toBe(str_repeat('a', 10))
        ->and((new StaffReason(str_repeat('ñ', 500)))->text)->toHaveLength(500);
});

it('no se puede construir fuera de los bordes', function (string $text): void {
    new StaffReason($text);
})->throws(InvalidArgumentException::class)->with([
    'nueve caracteres' => [str_repeat('a', 9)],
    'diez con espacios que no cuentan' => ['   abc    '],
    'quinientos uno, contados como letras' => [str_repeat('ñ', 501)],
]);
