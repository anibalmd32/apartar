<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El negocio aprueba un comprobante (`REQ-PAY-005`).
 *
 * **Lo que mueve el dinero no está aquí**: es `PaymentApplier::apply()`, el
 * colaborador que comparte con el pago manual (`T-062`) para que los dos den
 * lo mismo para el mismo importe. Esto es lo de alrededor, todo en **una**
 * transacción (RB-1):
 *
 *  1. **El apartado bloqueado y luego el comprobante** (RB-9). El orden es el
 *     de la subida y el de la cancelación —apartado, y después lo que cuelga
 *     de él—, así que dos de las tres no se pueden esperar en cruz.
 *  2. **Que siga en revisión, bajo el bloqueo** (SP-1, SP-3): dos personas
 *     aprobando a la vez se serializan, y la segunda se encuentra uno ya
 *     resuelto y el nombre de quien lo resolvió.
 *  3. **La tasa**: la vigente de **esa** organización con su fuente (RB-10,
 *     `ExchangeRateService::current()`), o la que el negocio escribió a mano,
 *     que se congela como `manual` y se audita (SP-7). En dólares no hace
 *     falta ninguna; en bolívares sin tasa, el aplicador responde `422`
 *     (SP-6).
 *  4. **La aplicación** —el pago con su snapshot, el reparto FIFO, la
 *     activación si esperaba la inicial, las cuotas, el balance y la
 *     transición— la hace `PaymentApplier`.
 *  5. **El sobrante sin confirmar deshace todo** (SP-5): se sabe cuánto sobra
 *     después de repartir, y si no es **exactamente** la cifra que se
 *     confirmó, se lanza y la transacción entera vuelve atrás.
 *  6. **El comprobante, aprobado**, con quién y cuándo.
 *  7. **La auditoría**, con el reparto (RB-11).
 *
 * Cualquier fallo antes del final deja el comprobante en revisión y el balance
 * como estaba (SP-8).
 *
 * ## Lo que no hace
 *
 *  - **No avisa al cliente** (RB-11, SP-10): los avisos de cobro son de
 *    `T-083`, como un listener encolado que no puede revertir esto.
 *  - **No mira la suspensión** (RB-12): aprobar es justo lo que no se bloquea
 *    por impago.
 */
final class ApproveReceiptAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws AuthorizationException si quien aprueba no concilia en esa organización
     * @throws ReceiptAlreadyProcessedException si ya no está en revisión (SP-1, SP-3)
     * @throws PaymentNotApplicableException si el apartado no cobra, la conversión da cero o no cubre la inicial
     * @throws MissingExchangeRateException si es en bolívares y no hay tasa (SP-6)
     * @throws UnconfirmedExcessException si sobra algo y no se confirmó (SP-5)
     */
    public function handle(Receipt $receipt, ApproveReceiptData $data, User $actor): AppliedPayment
    {
        Gate::forUser($actor)->authorize('review', $receipt);

        return DB::transaction(function () use ($receipt, $data, $actor): AppliedPayment {
            /** @var Layaway $layaway */
            $layaway = Layaway::query()->whereKey($receipt->layaway_id)->lockForUpdate()->firstOrFail();

            /** @var Receipt $locked */
            $locked = Receipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw ReceiptAlreadyProcessedException::of($locked);
            }

            $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());

            // El pago congela **lo conciliado** en su moneda, no lo que declaró
            // el cliente: es el par que reproduce los dólares con la tasa
            // (`REQ-FX-006` RB-4, RB-5), y lo declarado sigue en el
            // comprobante (RB-3). Con lo declarado, un negocio que corrige
            // Bs. 2.550 a Bs. 2.500 dejaba un pago que no multiplica.
            $applied = $this->applier->apply(
                $layaway,
                $data->settled(),
                $rate,
                $data->settledAmount,
                $data->settledCurrency,
                $locked,
                null,
                $data->note,
            );

            $excess = $applied->plan->unallocatedCents;

            if ($excess > 0 && $excess !== $data->confirmedExcessCents) {
                throw new UnconfirmedExcessException(new Money($excess));
            }

            $locked->forceFill([
                'status' => ReceiptStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit(
                AuditEvent::ReceiptApproved,
                subject: $locked,
                actor: $actor,
                before: ['status' => ReceiptStatus::PendingReview->value],
                after: $this->detailOf($applied, $data),
            );

            $receipt->setRawAttributes($locked->getAttributes(), sync: true);

            return $applied;
        });
    }

    /**
     * Lo que queda en la auditoría (RB-11): cuánto entró, a qué cuotas fue,
     * cuánto sobró, qué hizo con el apartado y con qué tasa. La tasa escrita
     * a mano va con su marca (SP-7): es la decisión que más conviene poder
     * revisar después.
     *
     * @return array<string, mixed>
     */
    private function detailOf(AppliedPayment $applied, ApproveReceiptData $data): array
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->pluck('number', 'id')
            ->all();

        return [
            'status' => ReceiptStatus::Approved->value,
            'payment_id' => $applied->payment->getKey(),
            'amount_cents_usd' => $applied->payment->amount_cents_usd->cents,
            'settled' => $data->settledAmount.' '.$data->settledCurrency->value,
            'rate_applied' => $applied->payment->rate_applied,
            'rate_source' => $applied->payment->rate_source->value,
            'down_payment_cents' => $applied->downPaymentCents,
            'allocations' => array_map(fn (array $allocation): array => [
                'installment' => $numbers[$allocation['installmentId']] ?? null,
                'cents' => $allocation['cents'],
            ], $applied->plan->allocations),
            'unallocated_cents' => $applied->plan->unallocatedCents,
            'layaway_status' => $applied->statusBefore->value.' → '.$applied->statusAfter->value,
        ] + ($data->manualRate() !== null ? ['rate_override' => $data->rateOverride] : []);
    }
}
