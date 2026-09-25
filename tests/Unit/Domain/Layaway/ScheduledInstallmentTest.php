<?php

declare(strict_types=1);

use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Una cuota: número, día de vencimiento e importe (`REQ-LAY-004`).
 */
it('nace sin nada pagado, en la moneda de su importe', function (): void {
    $installment = new ScheduledInstallment(1, CarbonImmutable::parse('2026-09-25', 'America/Caracas'), new Money(3_000, Currency::VES));

    expect($installment->paid->cents)->toBe(0)
        ->and($installment->paid->currency)->toBe(Currency::VES)
        ->and($installment->isPaid())->toBeFalse();
});

/**
 * El vencimiento es un día: lo que se guarda en `installments.due_date` es su
 * fecha de calendario, en la zona en que se generó.
 */
it('da su vencimiento como día de calendario', function (): void {
    $installment = new ScheduledInstallment(1, CarbonImmutable::parse('2026-09-25 00:00', 'America/Caracas'), new Money(3_000));

    expect($installment->dueDateString())->toBe('2026-09-25');
});

it('está saldada cuando lo pagado cubre el importe', function (int $paid, bool $isPaid): void {
    $installment = new ScheduledInstallment(2, CarbonImmutable::parse('2026-10-10'), new Money(3_000), new Money($paid));

    expect($installment->isPaid())->toBe($isPaid);
})->with([[0, false], [2_999, false], [3_000, true], [3_500, true]]);

it('se numera desde 1', function (): void {
    expect(fn () => new ScheduledInstallment(0, CarbonImmutable::parse('2026-09-25'), new Money(3_000)))
        ->toThrow(InvalidArgumentException::class, 'Las cuotas se numeran desde 1');
});
