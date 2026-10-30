<?php

declare(strict_types=1);

use App\Domain\Payment\DownPaymentProgress;

it('nombra la inicial entera cuando un pago la cubre de una vez', function (): void {
    $progress = new DownPaymentProgress(5_000);

    expect($progress->partial())->toBeFalse()
        ->and($progress->completesEarlierPayments())->toBeFalse()
        ->and($progress->label())->toBe('inicial de $50,00');
});

it('dice lo que falta cuando el pago es un abono a la inicial', function (): void {
    $progress = new DownPaymentProgress(2_000, 0, 3_000);

    expect($progress->partial())->toBeTrue()
        ->and($progress->label())->toBe('abono a la inicial de $20,00, faltan $30,00');
});

it('dice que la inicial quedó completa cuando el abono cierra los anteriores', function (): void {
    $progress = new DownPaymentProgress(3_000, 2_000, 0);

    expect($progress->completesEarlierPayments())->toBeTrue()
        ->and($progress->label())->toBe('abono de $30,00 que completa la inicial');
});

it('no nombra la inicial cuando el pago no le abona nada', function (): void {
    expect((new DownPaymentProgress(0))->label())->toBeNull();
});

it('no admite montos negativos', function (int $applied, int $before, int $missing): void {
    expect(fn () => new DownPaymentProgress($applied, $before, $missing))->toThrow(InvalidArgumentException::class);
})->with([[-1, 0, 0], [0, -1, 0], [0, 0, -1]]);
