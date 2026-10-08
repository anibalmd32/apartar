<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Money\Money;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El negocio registra un pago que recibió sin comprobante (`REQ-PAY-007`).
 *
 * **La mecánica no está aquí** (RB-2): es `PaymentApplier::apply()`, la misma
 * llamada que la aprobación con `$receipt = null` y `$registeredBy = $actor`,
 * así que el mismo importe deja el mismo resultado por los dos caminos —lo
 * afirma el test de equivalencia—. Lo de alrededor:
 *
 *  1. **Quién** (RB-3): alguien de esa organización que gestione la cobranza
 *     (`LayawayPolicy::manage`).
 *  2. **La cuenta de cobro, del negocio del apartado** (`REQ-ORG-002` RB-7):
 *     `ManualPaymentData` no recibe el apartado y no puede comprobarlo.
 *  3. **El doble clic** (SP-5): la clave de idempotencia del formulario. Si ya
 *     hay un pago con ella **para este apartado, este importe y esta
 *     moneda**, se devuelve ese pago y no se crea otro; si la clave registró
 *     otra cosa, no es un doble clic y se rechaza
 *     (`IdempotencyKeyReusedException`). Se mira antes de abrir la
 *     transacción y **otra vez bajo el bloqueo del apartado**: un envío
 *     cruzado que espera el bloqueo encuentra el pago del ganador antes de
 *     preguntar si el apartado aún cobra —si el ganador lo completó, la
 *     respuesta sería un falso «ya no admite pagos»—. Y si aun así chocan en
 *     el único de la tabla, el perdedor recibe el del ganador.
 *  4. **La aplicación**, con la tasa vigente o la escrita a mano (solo si hay
 *     algo que convertir), y **cómo se pagó** —método, cuenta y día—
 *     guardado en el pago (`PaymentOrigin`, `T-062`): sin comprobante, es lo
 *     único que queda además de la nota (RB-6).
 *  5. **El sobrante** se confirma por su cifra (SP-7, como `REQ-PAY-005`
 *     SP-5), o todo vuelve atrás.
 *  6. **La auditoría**, siempre (RB-5).
 *
 * Funciona con la organización suspendida (RB-8). El aviso al cliente (RB-7)
 * es de `T-083`.
 */
final class RegisterManualPaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
    ) {}

    /**
     * El pago registrado, o el que ya existía con esa clave.
     *
     * @throws AuthorizationException si quien registra no gestiona la cobranza de esa organización
     * @throws CrossTenantRelationException si la cuenta de cobro es de otra organización
     * @throws PaymentNotApplicableException si el apartado no cobra, la conversión da cero o no cubre la inicial
     * @throws MissingExchangeRateException si es en bolívares y no hay tasa (SP-3)
     * @throws UnconfirmedExcessException si sobra algo y no se confirmó esa cifra (SP-7)
     * @throws IdempotencyKeyReusedException si la clave ya registró otro pago (SP-5)
     */
    public function handle(Layaway $layaway, ManualPaymentData $data, User $actor): Payment
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $account = $data->paymentAccount;

        if ($account instanceof PaymentAccount && (int) $account->organization_id !== (int) $layaway->organization_id) {
            throw CrossTenantRelationException::between(PaymentAccount::class, Layaway::class);
        }

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Payment => $this->register($layaway, $data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            // SP-5 · el otro envío ganó la carrera con la misma clave.
            return $this->alreadyRegistered($layaway, $data) ?? throw $exception;
        }
    }

    private function register(Layaway $layaway, ManualPaymentData $data, User $actor): Payment
    {
        Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());

        $applied = $this->applier->apply(
            $layaway,
            $data->money(),
            $rate,
            $data->amount,
            $data->currency,
            null,
            $actor,
            $data->note,
            $data->idempotencyKey,
            $data->origin(),
        );

        $excess = $applied->plan->unallocatedCents;

        if ($excess > 0 && $excess !== $data->confirmedExcessCents) {
            throw new UnconfirmedExcessException(new Money($excess));
        }

        $this->audit(
            AuditEvent::PaymentRecorded,
            subject: $applied->payment,
            actor: $actor,
            after: $this->detailOf($applied, $data),
        );

        return $applied->payment;
    }

    /**
     * El pago que ya registró esta clave, si es **este mismo pago**: el mismo
     * apartado, el mismo importe y la misma moneda.
     *
     * @throws IdempotencyKeyReusedException si la clave registró otro pago
     */
    private function alreadyRegistered(Layaway $layaway, ManualPaymentData $data): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        $same = (int) $payment->layaway_id === (int) $layaway->getKey()
            && $payment->declared_currency === $data->currency
            && bccomp((string) $payment->declared_amount, $data->amount, 2) === 0;

        return $same ? $payment : throw new IdempotencyKeyReusedException;
    }

    /**
     * Lo que queda en la auditoría (RB-5): el importe, cómo se pagó, a qué
     * cuotas fue, el sobrante, el cambio de estado y la nota.
     *
     * @return array<string, mixed>
     */
    private function detailOf(AppliedPayment $applied, ManualPaymentData $data): array
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->pluck('number', 'id')
            ->all();

        return [
            'layaway' => $applied->payment->layaway_id,
            'amount_cents_usd' => $applied->payment->amount_cents_usd->cents,
            'paid' => $data->amount.' '.$data->currency->value,
            'payment_method' => $data->method->value,
            // Sin el sufijo `_id`: `AuditProperties` redacta toda clave con
            // `account_id` —es la cuenta global del cliente, `REQ-ACC-007`—, y
            // esta es la cuenta de cobro del propio negocio.
            'payment_account' => $data->paymentAccount?->getKey(),
            'paid_on' => $data->paidAt->toDateString(),
            'rate_applied' => $applied->payment->rate_applied,
            'rate_source' => $applied->payment->rate_source->value,
            'down_payment_cents' => $applied->downPaymentCents,
            'allocations' => array_map(fn (array $allocation): array => [
                'installment' => $numbers[$allocation['installmentId']] ?? null,
                'cents' => $allocation['cents'],
            ], $applied->plan->allocations),
            'unallocated_cents' => $applied->plan->unallocatedCents,
            'layaway_status' => $applied->statusBefore->value.' → '.$applied->statusAfter->value,
            'note' => $data->note,
        ] + ($data->manualRate() !== null ? ['rate_override' => $data->rateOverride] : []);
    }
}
