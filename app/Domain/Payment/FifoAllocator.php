<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final class FifoAllocator
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws InvalidArgumentException
     */
    public function plan(Layaway $layaway, Money $amount): AllocationPlan
    {
        $rows = $layaway->installments()->lockForUpdate()->get()->all();

        return self::planFor(
            Installment::scheduleOf($rows, new DateTimeZone($this->context->organization()->timezone)),
            $amount,
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public static function planFor(AmortizationSchedule $schedule, Money $amount): AllocationPlan
    {
        if ($amount->currency !== Currency::USD) {
            throw new InvalidArgumentException(sprintf(
                'Un pago se reparte en dólares, y llegó en %s: conviértelo antes.',
                $amount->currency->value,
            ));
        }

        if ($amount->cents <= 0) {
            throw new InvalidArgumentException(sprintf('Un pago se reparte si es mayor que cero, y es de %d centavos.', $amount->cents));
        }

        $left = $amount->cents;
        $allocations = [];

        foreach ($schedule->installments as $installment) {
            if ($installment->installmentId === null) {
                throw new LogicException(sprintf(
                    'La cuota %d no tiene fila en installments: un pago se reparte sobre el cronograma materializado, '
                    .'no sobre uno recién generado (Installment::scheduleOf()).',
                    $installment->number,
                ));
            }

            $outstanding = $installment->amount->cents - $installment->paid->cents;

            if ($left === 0 || $outstanding <= 0) {
                continue;
            }

            $cents = min($left, $outstanding);
            $allocations[] = ['installmentId' => $installment->installmentId, 'cents' => $cents];
            $left -= $cents;
        }

        return new AllocationPlan($allocations, $left);
    }
}
