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

/**
 * Reparte un pago entre las cuotas de un apartado, **de la más antigua a la
 * más nueva** (`REQ-PAY-005` RB-4, RB-6; `REQ-LAY-008` RB-3).
 *
 * ## La regla
 *
 * Se recorre el cronograma en orden y a cada cuota se le da **lo que le
 * falta**, nunca más: primero termina de cubrir la que estaba a medias, y el
 * resto cae en la siguiente, en cascada. Las cubiertas se saltan. Lo que sobra
 * cuando ya no queda nada por cubrir es `unallocatedCents`: no genera crédito
 * ni se devuelve (RB-6), y la UI exige confirmarlo (SP-5).
 *
 * «Más antigua» es **el número de cuota**: el cronograma va numerado 1…n
 * (`AmortizationSchedule` lo exige) y sus fechas son monótonas, porque la
 * reprogramación no deja que una cuota cruce a sus vecinas (`REQ-LAY-011`
 * RB-3). Una cuota en mora es siempre más antigua que una pendiente.
 *
 * ## Puro, con una puerta a la base
 *
 * El reparto es `planFor()`, que no toca la base: recibe el cronograma con lo
 * pagado de cada cuota y devuelve el `AllocationPlan`. Es lo que se prueba con
 * quinientos casos al azar, porque un fallo aquí descuadra el dinero de todos
 * los clientes. `plan()` es la firma de [22]: lee las cuotas del apartado
 * **con `FOR UPDATE`** —lo pagado de cada una no puede cambiar entre que se
 * reparte y se escribe— y delega. Se llama dentro de la transacción de
 * `PaymentApplier` (`T-058`), con el apartado ya bloqueado.
 *
 * La inicial **no** es una cuota y no pasa por aquí: la aprobación de la
 * inicial activa el apartado, y lo que exceda de ella se reparte después
 * sobre el cronograma recién creado (`REQ-PAY-005` RB-5, `T-058`).
 */
final class FifoAllocator
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * El reparto de ese importe sobre las cuotas del apartado, tal como están
     * en la base.
     *
     * @throws InvalidArgumentException si el importe no es un positivo en dólares
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
     * El reparto de ese importe sobre ese cronograma, sin base de datos.
     *
     * @throws InvalidArgumentException si el importe no es un positivo en dólares
     * @throws LogicException si el cronograma no está materializado: no hay filas a las que asignar
     */
    public static function planFor(AmortizationSchedule $schedule, Money $amount): AllocationPlan
    {
        if ($amount->currency !== Currency::USD) {
            throw new InvalidArgumentException(sprintf(
                'Un pago se reparte en dólares (REQ-FX-006 RB-1), y llegó en %s: conviértelo antes.',
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
