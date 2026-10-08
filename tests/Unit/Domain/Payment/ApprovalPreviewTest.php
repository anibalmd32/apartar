<?php

declare(strict_types=1);

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Payment\ApprovalPreview;

/**
 * Lo que haría aprobar, dicho para la pantalla de revisión (`REQ-PAY-004`
 * RB-6, `REQ-PAY-005` SP-5). Que salga de la aplicación real lo prueba
 * `PaymentApplierTest`; aquí, que se lee bien y que no se puede construir
 * descuadrada.
 */
/**
 * @param  list<array{number: int, cents: int, covers: bool}>  $allocations
 */
function approvalPreview(int $amount, int $downPayment, array $allocations, int $unallocated, LayawayStatus $before = LayawayStatus::Active, LayawayStatus $after = LayawayStatus::Active): ApprovalPreview
{
    return new ApprovalPreview(new Money($amount), '42.50000000', RateSource::Bcv, $downPayment, $allocations, $unallocated, $before, $after);
}

it('dice a qué cuota se aplica, como en el happy path', function (): void {
    expect(approvalPreview(3_000, 0, [['number' => 3, 'cents' => 3_000, 'covers' => true]], 0)->summary())->toBe('Se aplicará a la cuota 3.');
});

it('enumera las cuotas en cascada', function (): void {
    $allocations = [['number' => 1, 'cents' => 3_000, 'covers' => true], ['number' => 2, 'cents' => 3_000, 'covers' => true], ['number' => 3, 'cents' => 1_000, 'covers' => false]];

    expect(approvalPreview(7_000, 0, $allocations, 0)->summary())->toBe('Se aplicará a las cuotas 1, 2 y 3.');
});

it('dice que activa con la inicial, cuánto sobra y que completa', function (): void {
    $done = approvalPreview(21_000, 5_000, [['number' => 1, 'cents' => 15_000, 'covers' => true]], 1_000, LayawayStatus::PendingDownPayment, LayawayStatus::Completed);

    expect($done->summary())->toBe('Activará el apartado con la inicial de $50.00; se aplicará a la cuota 1; sobrarán $10.00 sin asignar; el apartado quedará pagado.')
        ->and($done->activates())->toBeTrue()
        ->and($done->completes())->toBeTrue()
        ->and($done->hasExcess())->toBeTrue()
        ->and($done->toArray())->toMatchArray([
            'amount' => '$210.00',
            'downPayment' => '$50.00',
            'unallocated' => '$10.00',
            'statusAfter' => 'completed',
            'rateSourceLabel' => RateSource::Bcv->label(),
        ]);
});

it('sin inicial ni sobrante, no los pinta', function (): void {
    expect(approvalPreview(3_000, 0, [['number' => 1, 'cents' => 3_000, 'covers' => true]], 0)->toArray())
        ->toMatchArray(['downPayment' => null, 'unallocated' => null]);
});

it('no se construye si las partes no suman el importe', function (): void {
    approvalPreview(3_000, 0, [['number' => 1, 'cents' => 2_000, 'covers' => false]], 0);
})->throws(InvalidArgumentException::class);
