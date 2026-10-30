<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Payment\PaymentConfirmation;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Collection;

function confirmedPayment(int $amount, int $unallocated, array $allocations): Payment
{
    $payment = (new Payment)->forceFill([
        'amount_cents_usd' => new Money($amount),
        'unallocated_cents' => new Money($unallocated),
    ]);

    $payment->setRelation('allocations', new Collection(array_map(function (array $allocation): PaymentAllocation {
        $row = (new PaymentAllocation)->forceFill(['amount_cents' => new Money($allocation[1])]);
        $row->setRelation('installment', (new Installment)->forceFill(['number' => $allocation[0]]));

        return $row;
    }, $allocations)));

    return $payment;
}

it('resume la inicial y la cuota a la que fue el pago', function (): void {
    expect(PaymentConfirmation::of(confirmedPayment(8_000, 0, [[1, 3_000]])))
        ->toBe('Pago registrado: $80,00 (inicial de $50,00; $30,00 a la cuota 1).');
});

it('enumera varias cuotas y lo que sobró', function (): void {
    expect(PaymentConfirmation::of(confirmedPayment(10_000, 1_000, [[2, 3_000], [1, 3_000], [3, 3_000]])))
        ->toBe('Pago registrado: $100,00 ($90,00 a las cuotas 1, 2 y 3; $10,00 sin asignar).');
});
