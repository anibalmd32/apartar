<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Money;
use App\Domain\Payment\AllocationPlan;
use App\Domain\Payment\InvalidReallocationException;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * El negocio mueve la aplicación de un pago entre las cuotas de su apartado
 * (`REQ-PAY-009`).
 *
 * Se reasigna **el reparto**, nunca el importe, la moneda ni la tasa (RB-1):
 * `payments` no se toca, y `unallocated_cents` tampoco (SP-7). Todo en una
 * transacción, con el apartado bloqueado (RB-6) —el mismo orden que el pago:
 * apartado, cuotas, y lo que cuelga de ellas—, así que una aprobación
 * concurrente espera o espera esto (SP-5):
 *
 *  1. **El apartado vivo** (RB-7): cancelado o expirado, no.
 *  2. **El reparto nuevo es un reparto** (`AllocationPlan`): una entrada por
 *     cuota, cada una de más de cero; de cuotas **de este apartado** (RB-3,
 *     SP-2), con la **misma suma** que el actual (RB-2, SP-1) y sin sobrepagar
 *     ninguna (SP-3).
 *  3. **Se borra el reparto del pago y se escribe el nuevo**: una asignación
 *     no se edita (`PaymentAllocation`), así que la invariante se comprueba
 *     sobre dos conjuntos completos.
 *  4. **Las cuotas que cambian** se recalculan con `InstallmentStatus::resolve()`
 *     y el «hoy» del negocio, como al pagar; y el apartado, con
 *     `LayawayBalanceRecalculator` (RB-4): `next_due_date`, y la vuelta a
 *     `overdue` o a `active` (SP-4), con su cupo, por la máquina de estados.
 *  5. **La auditoría**, con el reparto de antes y el de después y el motivo
 *     (RB-5).
 *
 * El saldo no cambia —la suma se conserva—, así que un apartado `completed`
 * sigue `completed`: SP-6 no se puede alcanzar (ver la nota de `T-063` en
 * [22]).
 */
final class ReallocatePaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
    ) {}

    /**
     * El reparto que queda.
     *
     * @param  list<array{installmentId: int, cents: int}>  $allocations
     *
     * @throws AuthorizationException si quien reasigna no gestiona la cobranza de esa organización
     * @throws InvalidArgumentException si el motivo no tiene entre 10 y 500 caracteres (RB-5)
     * @throws InvalidReallocationException si el reparto nuevo no se puede escribir
     */
    public function handle(Payment $payment, array $allocations, User $actor, string $reason): AllocationPlan
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);
        $motive = new StaffReason($reason);

        return DB::transaction(function () use ($payment, $allocations, $actor, $motive): AllocationPlan {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($payment->layaway_id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true)) {
                throw InvalidReallocationException::layawayClosed($locked->status);
            }

            /** @var array<int, Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->keyBy('id')->all();

            /** @var array<int, int> $current */
            $current = PaymentAllocation::query()->where('payment_id', $payment->getKey())->lockForUpdate()->get()
                ->mapWithKeys(fn (PaymentAllocation $allocation): array => [(int) $allocation->installment_id => $allocation->amount_cents->cents])
                ->all();

            $plan = $this->validated($allocations, $current, $installments, $payment);
            $statusBefore = $locked->status;

            PaymentAllocation::query()->where('payment_id', $payment->getKey())->delete();

            foreach ($plan->allocations as $allocation) {
                (new PaymentAllocation)->forceFill([
                    'payment_id' => $payment->getKey(),
                    'installment_id' => $allocation['installmentId'],
                    'amount_cents' => new Money($allocation['cents']),
                ])->save();
            }

            $this->resettle($installments, $current, $plan);
            $this->balance->recalculate($locked);

            $this->audit(
                AuditEvent::PaymentReallocated,
                subject: $payment,
                actor: $actor,
                before: ['allocations' => $this->numbered($current, $installments)],
                after: [
                    'allocations' => $this->numbered(array_column($plan->allocations, 'cents', 'installmentId'), $installments),
                    'reason' => $motive->text,
                    'layaway_status' => $statusBefore->value.' → '.$locked->status->value,
                ],
            );

            return $plan;
        });
    }

    /**
     * El reparto nuevo, comprobado contra el actual y contra las cuotas.
     *
     * @param  list<array{installmentId: int, cents: int}>  $allocations
     * @param  array<int, int>  $current
     * @param  array<int, Installment>  $installments
     */
    private function validated(array $allocations, array $current, array $installments, Payment $payment): AllocationPlan
    {
        if ($current === []) {
            throw InvalidReallocationException::nothingAllocated();
        }

        try {
            $plan = new AllocationPlan($allocations, $payment->unallocated_cents->cents);
        } catch (InvalidArgumentException $exception) {
            throw InvalidReallocationException::malformed($exception->getMessage());
        }

        foreach ($plan->allocations as $allocation) {
            if (! isset($installments[$allocation['installmentId']])) {
                throw InvalidReallocationException::foreignInstallment();
            }
        }

        if ($plan->allocatedCents() !== array_sum($current)) {
            throw InvalidReallocationException::sumMismatch(array_sum($current), $plan->allocatedCents());
        }

        foreach ($plan->allocations as $allocation) {
            $installment = $installments[$allocation['installmentId']];
            // Lo que la cuota admite de este pago: su importe menos lo que le
            // pagaron los **demás** pagos.
            $room = $installment->amount_cents->cents - ($installment->paid_cents->cents - ($current[$allocation['installmentId']] ?? 0));

            if ($allocation['cents'] > $room) {
                throw InvalidReallocationException::overpaysInstallment($installment->number, max(0, $room));
            }
        }

        $proposed = array_column($plan->allocations, 'cents', 'installmentId');
        ksort($proposed);
        ksort($current);

        if ($proposed === $current) {
            throw InvalidReallocationException::unchanged();
        }

        return $plan;
    }

    /**
     * Lo pagado y el estado de **todas** las cuotas, con el «hoy» del negocio
     * para la mora, y se guardan las que cambian —como al pagar
     * (`PaymentApplier::allocate()`)—. No solo las que el reparto toca: una
     * cuota que pasó la gracia esta mañana y la pasada de las 06:00 aún no
     * marcó haría que el recalculador devolviera el apartado a `active` para
     * que la pasada lo devolviera a `overdue` horas después, con dos avisos
     * (`REQ-PAY-005` RB-8).
     *
     * Una cuota que queda cubierta toma como `paid_at` el día del pago **más
     * reciente** de los que la cubren, no el de la reasignación: cuándo quedó
     * pagada es cuándo llegó el dinero, y reasignar semanas después no lo
     * cambia.
     *
     * @param  array<int, Installment>  $installments
     * @param  array<int, int>  $current
     */
    private function resettle(array $installments, array $current, AllocationPlan $plan): void
    {
        $now = CarbonImmutable::now();

        foreach ($installments as $id => $installment) {
            $paid = new Money($installment->paid_cents->cents + $plan->centsFor($id) - ($current[$id] ?? 0));
            $status = InstallmentStatus::resolve($installment->amount_cents, $paid, $this->overdue->isPastGrace($installment, $now));

            $installment->forceFill([
                'paid_cents' => $paid,
                'status' => $status,
                'paid_at' => $status === InstallmentStatus::Paid ? ($installment->paid_at ?? $this->coveredAt($id)) : null,
            ]);

            if ($installment->isDirty()) {
                $installment->save();
            }
        }
    }

    /**
     * El día del pago más reciente que reparte algo a esa cuota, con el
     * reparto nuevo ya escrito.
     */
    private function coveredAt(int $installmentId): CarbonImmutable
    {
        $settled = Payment::query()
            ->whereIn('id', PaymentAllocation::query()->where('installment_id', $installmentId)->select('payment_id'))
            ->max('settled_at');

        return is_string($settled) ? CarbonImmutable::parse($settled) : CarbonImmutable::now();
    }

    /**
     * Para la auditoría: el reparto por número de cuota.
     *
     * @param  array<int, int>  $centsById
     * @param  array<int, Installment>  $installments
     * @return list<array{installment: int, cents: int}>
     */
    private function numbered(array $centsById, array $installments): array
    {
        $rows = [];

        foreach ($centsById as $id => $cents) {
            $rows[] = ['installment' => $installments[$id]->number, 'cents' => $cents];
        }

        usort($rows, fn (array $a, array $b): int => $a['installment'] <=> $b['installment']);

        return $rows;
    }
}
