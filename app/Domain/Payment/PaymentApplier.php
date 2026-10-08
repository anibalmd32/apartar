<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\ManualRate;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\StaleRate;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Aplica un pago a un apartado ([05 §5], `REQ-PAY-005`, `REQ-PAY-007`).
 *
 * Es **el colaborador compartido** de la aprobación de un comprobante
 * (`T-061`) y del pago manual (`T-062`), y existe para que los dos den
 * resultados idénticos para el mismo importe: escrito una vez y probado aquí,
 * el test de equivalencia de `REQ-PAY-007` RB-2 es posible. **Es el único que
 * crea `Payment` y `PaymentAllocation`.**
 *
 * ## En orden, en la transacción del llamador
 *
 *  1. **Bloquea el apartado** y comprueba que cobra (`acceptsPayments()`).
 *  2. **Convierte a dólares** con la tasa (`REQ-FX-006` RB-2): una
 *     conversión que no llega a un centavo se rechaza (SP-4). Todo esto antes
 *     de escribir nada.
 *  3. **La inicial no es una cuota** (`T-056`): con el apartado en
 *     `pending_down_payment`, lo **activa** con `ActivateLayawayAction`, que
 *     materializa el cronograma y deja la inicial aplicada (`REQ-PAY-005`
 *     RB-5). Un pago que no cubre la inicial entera se rechaza.
 *  4. **Reparte el resto** con `FifoAllocator::plan()`, que bloquea las cuotas
 *     (RB-4). Si no queda resto, no lo llama: un reparto de cero no existe.
 *  5. **Crea el pago entero en un `INSERT`** —es inmutable (`REQ-FX-006`
 *     RB-3)— con su snapshot de tasa, su sobrante y la clave de idempotencia,
 *     y una fila de `payment_allocations` por asignación.
 *  6. **Actualiza las cuotas**: lo pagado, el estado de **todas** con
 *     `InstallmentStatus::resolve()` —la regla de la mora, con la gracia y el
 *     «hoy» del negocio (`DetectOverdueAction::isPastGrace()`)— y `paid_at`
 *     cuando queda cubierta.
 *  7. **Recalcula el balance** (`LayawayBalanceRecalculator`, `REQ-PAY-008`),
 *     que además decide la transición —`completed` con la tolerancia, vuelta
 *     de `overdue` a `active`— y se la pide a la máquina, que ajusta el cupo
 *     del plan.
 *
 * Abre una transacción por si se le llama sin una, pero se espera dentro de la
 * del llamador ([05 §5.1]): el comprobante se marca y se audita allí, y si
 * algo falla después, este pago se deshace con todo lo demás (SP-8).
 *
 * ## La tasa
 *
 * Llega de tres formas. Un `RateSnapshot` —la vigente de la organización,
 * `ExchangeRateService::current()`— se congela con su fuente y su fecha de
 * publicación. Una `ManualRate` —la que el negocio escribe al conciliar,
 * SP-3— fuerza `rate_source = manual` y deja `rate_published_at` nula: no la
 * publicó nadie. Y `null` solo vale para un pago **en dólares**, que no
 * convierte nada: aprobar exige tasa cuando la moneda es VES (`REQ-PAY-005`
 * RB-10). Un pago en dólares guarda `rate_applied = 1` y la fuente vigente
 * (RB-4).
 *
 * La clave de idempotencia la escribe el `INSERT` (`REQ-PAY-007` SP-5), y la
 * violación de `UNIQUE(organization_id, idempotency_key)` **sube** a quien
 * llamó, que la traduce a «ya registrado» (`T-062`).
 */
final class PaymentApplier
{
    /** `REQ-FX-006` RB-4: un pago en dólares no convierte. */
    private const UNIT_RATE = '1.00000000';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivateLayawayAction $activation,
        private readonly FifoAllocator $allocator,
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * Al volver, el modelo que se pasó lleva el balance y el estado nuevos.
     *
     * @param  Money  $settled  lo que el negocio confirma haber recibido, en su moneda
     * @param  string  $declaredAmount  lo declarado, con dos decimales, en `$declaredCurrency`
     * @param  PaymentOrigin|null  $origin  cómo se pagó; sin él, el del comprobante si viene (`T-062`)
     *
     * @throws PaymentNotApplicableException si el apartado no cobra, la conversión da cero o no cubre la inicial
     * @throws MissingExchangeRateException si el pago es en bolívares y no hay tasa
     * @throws InvalidArgumentException si el importe no es positivo, o un pago manual no dice quién lo registró
     */
    public function apply(
        Layaway $layaway,
        Money $settled,
        RateSnapshot|ManualRate|null $rate,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy,
        ?string $note,
        ?string $idempotencyKey = null,
        ?PaymentOrigin $origin = null,
    ): AppliedPayment {
        PaymentAmounts::assertPositive($declaredAmount, $declaredCurrency, 'declarado');

        if ($settled->cents <= 0) {
            throw new InvalidArgumentException(sprintf('Un pago conciliado es mayor que cero, y es de %d céntimos (REQ-PAY-005 SP-4).', $settled->cents));
        }

        if ($receipt === null && $registeredBy === null) {
            throw new InvalidArgumentException('Un pago sin comprobante dice quién lo registró (REQ-PAY-007: registered_by obligatorio).');
        }

        return DB::transaction(function () use ($layaway, $settled, $rate, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, $note, $idempotencyKey, $origin): AppliedPayment {
            // RB-9 · bloqueado, y por el global scope: el de otro negocio no
            // existe para esta búsqueda.
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($receipt !== null && $receipt->layaway_id !== $locked->getKey()) {
                throw new LogicException(sprintf('El comprobante %d es del apartado %d, no del %d.', $receipt->getKey(), $receipt->layaway_id, $locked->getKey()));
            }

            $statusBefore = $locked->status;

            if (! $statusBefore->acceptsPayments()) {
                throw PaymentNotApplicableException::layawayNotCollecting($statusBefore);
            }

            [$dollars, $snapshot] = $this->toDollars($settled, $rate);
            $downPayment = 0;

            if ($statusBefore === LayawayStatus::PendingDownPayment) {
                $required = $locked->terms()->totalDownPayment();

                if ($dollars->cents < $required->cents) {
                    throw PaymentNotApplicableException::belowDownPayment($dollars, $required);
                }

                // RB-5 · activa: cronograma, inicial aplicada y, con
                // financiado cero, completado.
                $this->activation->handle($locked);
                $downPayment = $required->cents;
            }

            $excess = $dollars->cents - $downPayment;

            // RB-4, RB-6 · el resto, FIFO; lo que no cabe queda sin asignar.
            $plan = $excess > 0
                ? $this->allocator->plan($locked, new Money($excess))
                : new AllocationPlan([], 0);

            // Cómo se pagó: lo que diga quien llama, o el comprobante si viene.
            $origin ??= $receipt instanceof Receipt ? PaymentOrigin::ofReceipt($receipt) : null;

            $payment = $this->insertPayment($locked, $dollars, $plan, $snapshot, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, $note, $idempotencyKey, $origin);

            $this->allocate($locked, $payment, $plan);

            $this->balance->recalculate($locked);

            $layaway->setRawAttributes($locked->getAttributes(), sync: true);

            return new AppliedPayment($payment, $plan, $statusBefore, $locked->status, $downPayment);
        });
    }

    /**
     * Lo que haría `apply()` con estos datos, **sin que pase** (`REQ-PAY-004`
     * RB-6, `REQ-PAY-005` SP-5).
     *
     * Ejecuta la aplicación entera —conversión, activación, reparto, cuotas,
     * recálculo— dentro de una transacción que se deshace siempre, y devuelve
     * lo que dejó. No es una segunda cuenta que imita a la primera: es la
     * misma, así que el aviso de la pantalla y lo que escribe la aprobación no
     * pueden divergir. Lo que se despacha «después del commit» —los eventos de
     * transición— se descarta con el rollback, y el apartado que se pasa no se
     * toca: se trabaja sobre una copia.
     *
     * Bloquea el apartado y sus cuotas mientras dura, igual que aprobar: dos
     * vistas previas del mismo apartado se esperan, y ninguna ve la otra. Si
     * la aplicación completaría el apartado, bloquea también el contador de
     * apartados activos de la organización (`LimitEnforcer`) hasta el
     * *rollback*: unos milisegundos en que un apartado nuevo del mismo negocio
     * espera. Lo que no se deshace son los avisos del log de una anomalía que
     * ya existía y el `AUTO_INCREMENT` de las filas de prueba.
     *
     * **Se llama sin transacción abierta** —como la llaman los controladores—:
     * dentro de otra, un *deadlock* deja a MySQL sin el *savepoint* que el
     * `finally` quiere deshacer, y el error que sube es ese y no el bloqueo.
     *
     * @throws PaymentNotApplicableException si el apartado no cobra, la conversión da cero o no cubre la inicial
     * @throws MissingExchangeRateException si el pago es en bolívares y no hay tasa
     */
    public function preview(
        Layaway $layaway,
        Money $settled,
        RateSnapshot|ManualRate|null $rate,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy = null,
        ?PaymentOrigin $origin = null,
    ): ApprovalPreview {
        DB::beginTransaction();

        try {
            $applied = $this->apply(clone $layaway, $settled, $rate, $declaredAmount, $declaredCurrency, $receipt, $registeredBy, null, null, $origin);

            /** @var array<int, Installment> $installments */
            $installments = Installment::query()->where('layaway_id', $layaway->getKey())->get()->keyBy('id')->all();

            return new ApprovalPreview(
                amount: $applied->payment->amount_cents_usd,
                rate: $applied->payment->rate_applied,
                rateSource: $applied->payment->rate_source,
                downPaymentCents: $applied->downPaymentCents,
                allocations: array_map(fn (array $allocation): array => [
                    'number' => $installments[$allocation['installmentId']]->number,
                    'cents' => $allocation['cents'],
                    'covers' => $installments[$allocation['installmentId']]->status === InstallmentStatus::Paid,
                ], $applied->plan->allocations),
                unallocatedCents: $applied->plan->unallocatedCents,
                statusBefore: $applied->statusBefore,
                statusAfter: $applied->statusAfter,
                staleRate: StaleRate::in($rate, $settled->currency),
            );
        } finally {
            DB::rollBack();
        }
    }

    /**
     * El importe en dólares y la tasa que se congela con él.
     *
     * @return array{Money, array{rate: string, source: RateSource, publishedAt: ?CarbonImmutable}}
     */
    private function toDollars(Money $settled, RateSnapshot|ManualRate|null $rate): array
    {
        if ($settled->currency === Currency::USD) {
            return [$settled, ['rate' => self::UNIT_RATE] + $this->sourceOf($rate)];
        }

        if ($rate === null) {
            throw MissingExchangeRateException::whileSettling($this->rates->sourceFor($this->context->organization()));
        }

        // La tasa con la que se convierte es **la misma cadena** que se
        // congela en `rate_applied`, que es `DECIMAL(18,8)`. Una tasa con más
        // decimales —la manual configurada solo exige que sea numérica— se
        // convertiría con todos y MySQL guardaría otra, redondeada: el pago
        // diría haber usado una tasa que no usó (`REQ-FX-006` RB-3).
        $applied = self::toRateScale($rate->rate);
        $snapshot = new RateSnapshot($applied, RateSource::Manual, CarbonImmutable::now(), CarbonImmutable::now());

        $dollars = $settled->convertTo(Currency::USD, $snapshot);

        if ($dollars->cents <= 0) {
            // SP-4 · Bs. 0,01 a 42,50 no es un pago.
            throw PaymentNotApplicableException::convertsToZero($settled, $applied);
        }

        return [$dollars, ['rate' => $applied] + $this->sourceOf($rate)];
    }

    /**
     * La tasa a la escala de la columna, *half-up* (`REQ-FX-001` RB-5). Las
     * que ya caben —todas las de la ingesta y toda `ManualRate`— no cambian
     * de valor.
     */
    private static function toRateScale(string $rate): string
    {
        Money::assertUsableRate($rate);

        return bcadd($rate, '0.000000005', ManualRate::SCALE);
    }

    /**
     * La fuente y la fecha de publicación que se congelan: las del snapshot,
     * o `manual` sin fecha para una tasa escrita a mano; y para un pago en
     * dólares sin tasa, la fuente configurada del negocio, también sin fecha.
     *
     * @return array{source: RateSource, publishedAt: ?CarbonImmutable}
     */
    private function sourceOf(RateSnapshot|ManualRate|null $rate): array
    {
        return match (true) {
            $rate instanceof RateSnapshot => ['source' => $rate->source, 'publishedAt' => $rate->publishedAt],
            $rate instanceof ManualRate => ['source' => RateSource::Manual, 'publishedAt' => null],
            default => ['source' => $this->rates->sourceFor($this->context->organization()), 'publishedAt' => null],
        };
    }

    /**
     * El pago, **entero en el `INSERT`**: después ya no se puede tocar
     * (`Payment::IMMUTABLE_COLUMNS`).
     *
     * @param  array{rate: string, source: RateSource, publishedAt: ?CarbonImmutable}  $snapshot
     */
    private function insertPayment(
        Layaway $layaway,
        Money $dollars,
        AllocationPlan $plan,
        array $snapshot,
        string $declaredAmount,
        Currency $declaredCurrency,
        ?Receipt $receipt,
        ?User $registeredBy,
        ?string $note,
        ?string $idempotencyKey,
        ?PaymentOrigin $origin,
    ): Payment {
        $payment = new Payment;

        // Sin `organization_id`: lo pone `BelongsToOrganization` desde el
        // tenant fijado, que es el del apartado.
        $payment->forceFill([
            'layaway_id' => $layaway->getKey(),
            'receipt_id' => $receipt?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'amount_cents_usd' => $dollars,
            'unallocated_cents' => new Money($plan->unallocatedCents),
            'declared_currency' => $declaredCurrency,
            'declared_amount' => $declaredAmount,
            'rate_applied' => $snapshot['rate'],
            'rate_source' => $snapshot['source'],
            'rate_published_at' => $snapshot['publishedAt'],
            'settled_at' => CarbonImmutable::now(),
            'registered_by' => $registeredBy?->getKey(),
            'note' => $note,
            ...($origin?->toColumns() ?? []),
        ])->save();

        return $payment;
    }

    /**
     * Una fila de reparto por asignación, y **todas** las cuotas del apartado
     * con lo que llevan pagado y su estado.
     *
     * Todas y no solo las repartidas: el recalculador decide la mora del
     * apartado leyendo el estado de cada cuota, y una que pasó su gracia esta
     * madrugada —antes de la pasada de las 06:00, o con la de ayer fallida
     * (`REQ-LAY-007` SP-5)— seguiría `pending`. Cubrir la vencida anterior
     * devolvería el apartado a `active` con una cuota en mora sin cubrir
     * (`REQ-PAY-005` RB-8). Es la regla de la pasada diaria
     * (`InstallmentStatus::resolve()` con `isPastGrace()`), y solo se escribe
     * lo que cambia.
     *
     * Las cuotas se escriben **por su modelo**, que guarda su importe
     * (`Installment::IMMUTABLE_COLUMNS`), y bloqueadas: después del apartado,
     * como en el resto de la cobranza.
     */
    private function allocate(Layaway $layaway, Payment $payment, AllocationPlan $plan): void
    {
        $now = CarbonImmutable::now();

        foreach ($plan->allocations as $allocation) {
            $row = new PaymentAllocation;
            $row->forceFill([
                'payment_id' => $payment->getKey(),
                'installment_id' => $allocation['installmentId'],
                'amount_cents' => new Money($allocation['cents']),
            ])->save();
        }

        foreach ($layaway->installments()->lockForUpdate()->get() as $installment) {
            $paid = new Money($installment->paid_cents->cents + $plan->centsFor((int) $installment->getKey()));
            $status = InstallmentStatus::resolve($installment->amount_cents, $paid, $this->overdue->isPastGrace($installment, $now));

            $installment->forceFill([
                'paid_cents' => $paid,
                'status' => $status,
                'paid_at' => $status === InstallmentStatus::Paid ? ($installment->paid_at ?? $now) : null,
            ]);

            if ($installment->isDirty()) {
                $installment->save();
            }
        }
    }
}
