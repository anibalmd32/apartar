<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Settings\SettingKey;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Support\ModelState;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * El balance materializado de un apartado (`REQ-PAY-008`).
 *
 * **Única clase que escribe `paid_cents`, `remaining_cents` y
 * `next_due_date`** (RB-7), y la que **decide** el cuarto campo, `status`,
 * cuando lo mueve el dinero: completar con la tolerancia (`REQ-PAY-005` RB-7)
 * y seguir a las cuotas en la mora (`REQ-LAY-007` RB-2, RB-6). La escritura
 * del estado no es suya —es de `LayawayStateMachine::transition()`, que
 * valida la matriz, escribe la marca, ajusta el cupo y emite el evento
 * (`REQ-LAY-005` RB-2)—: aquí se elige el destino y se le pide. Lo impiden la
 * guarda de `Layaway` y `LayawayBalanceWritesTest`.
 *
 * ## De dónde sale cada término de RB-3
 *
 * Ninguna columna guarda «la inicial aplicada», y [05 §4] tampoco guardaba el
 * sobrante. Lo decidido en `T-057`:
 *
 *  - **inicial aplicada** = la inicial total de `terms()` si el apartado se
 *    activó (`activated_at` no nulo), y cero si no. La inicial no es una
 *    cuota (`T-056`): solo se aplica activando, y activar solo ocurre al
 *    aprobarse la inicial (`REQ-LAY-004` RB-1). `activated_at` no se
 *    reescribe al volver de la mora, así que dice exactamente eso.
 *  - **sobrante** = `payments.unallocated_cents`, que es nueva y se escribe en
 *    el `INSERT` con lo que `FifoAllocator` dijo que no cabía. Derivarlo
 *    habría hecho que la primera invariante se cumpliera sola.
 *
 * ## Lo pagado se deriva de las cuotas, y los pagos lo verifican
 *
 * `paid_cents = sum(installments.paid_cents) + inicial aplicada` (la segunda
 * invariante), y no la primera, por una razón de orden: el recalculador corre
 * **dentro** de la activación, después de insertar el cronograma y antes de
 * que nada haya repartido el excedente (`T-058`). Ahí las cuotas y la inicial
 * ya cuentan la historia entera —y con financiado cero, la cuentan como
 * completa—, y los pagos aún no tienen por qué. La primera invariante es la
 * comprobación cruzada: `diff()` la mide y la reporta.
 *
 * El resto: `remaining_cents = max(0, total − paid)` (SP-2: un exceso se
 * acota y se registra, `layaway_balance.overpaid`), y `next_due_date` es el
 * menor vencimiento entre las cuotas **sin cubrir** (`paid < amount`, que es
 * lo que `InstallmentStatus::resolve()` llama `paid`), o `null`.
 *
 * ## Con la fila bloqueada, y sin reloj
 *
 * Bloquea el apartado y luego sus cuotas, el mismo orden que el pago, la mora
 * y la reprogramación; si quien llama ya los tiene, el bloqueo es suyo y esto
 * no espera. No pregunta la hora: el estado de cada cuota lo decide quien
 * conoce el «hoy» del negocio (la mora, el pago) con `InstallmentStatus`, y
 * aquí solo se lee.
 */
final class LayawayBalanceRecalculator
{
    public function __construct(
        private readonly LayawayStateMachine $machine,
        private readonly Settings $settings,
    ) {}

    /**
     * Recalcula desde las cuotas y la inicial, escribe lo que cambió y pide la
     * transición que corresponda. Acota `remaining_cents` a cero.
     *
     * Al volver, el modelo que se pasó lleva el balance y el estado nuevos.
     */
    public function recalculate(Layaway $layaway): void
    {
        DB::transaction(function () use ($layaway): void {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            /** @var list<Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->all();

            $derived = $this->derive($locked, $installments);

            if ($derived['overpaid'] > 0) {
                // SP-2 · se acota y se reporta; no se lanza, porque bloquear
                // el pago siguiente no arregla un exceso que ya ocurrió.
                Log::warning('layaway_balance.overpaid', [
                    'layaway_id' => $locked->getKey(),
                    'organization_id' => $locked->organization_id,
                    'paid_cents' => $derived['paid_cents'],
                    'total_cents' => $locked->total_cents->cents,
                    'overpaid_cents' => $derived['overpaid'],
                ]);
            }

            $changes = $this->changedColumns($this->stored($locked), $derived);

            if ($changes !== []) {
                // RB-7 · la única escritura de estas columnas después del
                // `INSERT`, por el *query builder*: la guarda del modelo
                // rechaza cualquier otra. Solo lo que cambió, para que
                // recalcular un apartado que cuadra no toque la fila. Las
                // columnas van escritas en la llamada para que
                // `LayawayBalanceWritesTest` la reconozca.
                Layaway::query()->whereKey($locked->getKey())->update(array_intersect_key([
                    'paid_cents' => $derived['paid_cents'],
                    'remaining_cents' => $derived['remaining_cents'],
                    'next_due_date' => $derived['next_due_date'],
                ], $changes));
            }

            $target = $this->targetStatus($locked->status, $derived['remaining_cents'], $installments);

            if ($target !== null) {
                $this->machine->transition($locked, $target);
            }

            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su recálculo.');
            // `ModelState::adopt()`, y no `setRawAttributes(..., sync: true)`:
            // así el modelo vuelve con el saldo nuevo aunque quien llama ya
            // hubiera leído `paid_cents` (`T-064`).
            ModelState::adopt($layaway, $fresh->getAttributes());
        });
    }

    /**
     * Compara lo materializado con lo derivado **sin escribir** ni bloquear, y
     * mide además la primera invariante contra los pagos.
     *
     * `null` si el apartado cuadra por los cuatro lados.
     */
    public function diff(Layaway $layaway): ?BalanceDiscrepancy
    {
        /** @var Layaway $current */
        $current = Layaway::query()->whereKey($layaway->getKey())->firstOrFail();

        /** @var list<Installment> $installments */
        $installments = $current->installments()->get()->all();

        return $this->discrepancyOf($current, $installments, $this->paidFromPayments($current));
    }

    /**
     * `diff()` para un lote, **sin `N+1`** (`REQ-PAY-008` SP-4, `T-064`): las
     * cuotas de todos en una consulta y lo pagado de todos en otra, agrupada.
     * La comparación es la misma —`discrepancyOf()`—, así que la
     * reconciliación no puede discrepar de lo que mide `diff()`.
     *
     * Los apartados van como los leyó quien llama, de la organización fijada.
     *
     * @param  list<Layaway>  $layaways
     * @return array<int, BalanceDiscrepancy> por id de apartado, solo los descuadrados
     */
    public function diffMany(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $ids = array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);

        /** @var array<int, list<Installment>> $installments */
        $installments = [];

        foreach (Installment::query()->whereIn('layaway_id', $ids)->orderBy('number')->get() as $installment) {
            $installments[(int) $installment->layaway_id][] = $installment;
        }

        $fromPayments = Payment::query()
            ->whereIn('layaway_id', $ids)
            ->groupBy('layaway_id')
            ->selectRaw('layaway_id, SUM(amount_cents_usd - unallocated_cents) AS paid')
            ->pluck('paid', 'layaway_id')
            ->all();

        $discrepancies = [];

        foreach ($layaways as $layaway) {
            $id = (int) $layaway->getKey();
            $discrepancy = $this->discrepancyOf($layaway, $installments[$id] ?? [], (int) ($fromPayments[$id] ?? 0));

            if ($discrepancy instanceof BalanceDiscrepancy) {
                $discrepancies[$id] = $discrepancy;
            }
        }

        return $discrepancies;
    }

    /**
     * Lo guardado contra lo derivado de las cuotas y la inicial, más la
     * transición que tocaría y lo que dicen los pagos.
     *
     * @param  list<Installment>  $installments
     */
    private function discrepancyOf(Layaway $layaway, array $installments, int $fromPayments): ?BalanceDiscrepancy
    {
        $derived = $this->derive($layaway, $installments);
        $fields = $this->changedColumns($this->stored($layaway), $derived);

        $target = $this->targetStatus($layaway->status, $derived['remaining_cents'], $installments);

        if ($target !== null) {
            $fields['status'] = ['stored' => $layaway->status->value, 'derived' => $target->value];
        }

        if ($fields === [] && $fromPayments === $derived['paid_cents'] && $derived['overpaid'] === 0) {
            return null;
        }

        return new BalanceDiscrepancy(
            layawayId: (int) $layaway->getKey(),
            fields: $fields,
            paidFromPayments: $fromPayments,
            paidFromSchedule: $derived['paid_cents'],
            overpaidCents: $derived['overpaid'],
        );
    }

    /**
     * Lo que valen las tres columnas según las cuotas y la inicial.
     *
     * @param  list<Installment>  $installments
     * @return array{paid_cents: int, remaining_cents: int, next_due_date: ?string, overpaid: int}
     */
    private function derive(Layaway $layaway, array $installments): array
    {
        $paid = $this->downPaymentApplied($layaway);
        $nextDue = null;

        foreach ($installments as $installment) {
            $paid += $installment->paid_cents->cents;

            if ($installment->paid_cents->cents < $installment->amount_cents->cents) {
                $day = $installment->due_date->toDateString();
                $nextDue = $nextDue === null || $day < $nextDue ? $day : $nextDue;
            }
        }

        $total = $layaway->total_cents->cents;

        return [
            'paid_cents' => $paid,
            'remaining_cents' => max(0, $total - $paid),
            'next_due_date' => $nextDue,
            'overpaid' => max(0, $paid - $total),
        ];
    }

    /**
     * La inicial no es una cuota y no tiene fila: se aplica activando, así
     * que un apartado activado la lleva aplicada entera, y uno sin activar no
     * lleva nada.
     */
    private function downPaymentApplied(Layaway $layaway): int
    {
        return $layaway->activated_at === null ? 0 : $layaway->terms()->totalDownPayment()->cents;
    }

    /**
     * Primera invariante de RB-3: lo que trajeron los pagos menos lo que no
     * cupo.
     */
    private function paidFromPayments(Layaway $layaway): int
    {
        return (int) Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->sum(DB::raw('amount_cents_usd - unallocated_cents'));
    }

    /**
     * @return array{paid_cents: int, remaining_cents: int, next_due_date: ?string}
     */
    private function stored(Layaway $layaway): array
    {
        return [
            'paid_cents' => $layaway->paid_cents->cents,
            'remaining_cents' => $layaway->remaining_cents->cents,
            'next_due_date' => $layaway->next_due_date?->toDateString(),
        ];
    }

    /**
     * Las columnas cuyo valor guardado no es el derivado, con los dos.
     *
     * @param  array{paid_cents: int, remaining_cents: int, next_due_date: ?string}  $stored
     * @param  array{paid_cents: int, remaining_cents: int, next_due_date: ?string, overpaid: int}  $derived
     * @return array<'paid_cents'|'remaining_cents'|'next_due_date', array{stored: int|string|null, derived: int|string|null}>
     */
    private function changedColumns(array $stored, array $derived): array
    {
        $changed = [];

        foreach (['paid_cents', 'remaining_cents', 'next_due_date'] as $column) {
            if ($stored[$column] !== $derived[$column]) {
                $changed[$column] = ['stored' => $stored[$column], 'derived' => $derived[$column]];
            }
        }

        return $changed;
    }

    /**
     * La transición que corresponde al balance nuevo, o `null`.
     *
     * Solo para los que se están cobrando: desde `pending_down_payment` sale
     * la activación, y los terminales no se mueven. Saldado dentro de la
     * tolerancia → `completed` (`REQ-PAY-005` RB-7), también desde la mora.
     * Si no, el apartado sigue a sus cuotas (`REQ-LAY-007` RB-2): con alguna
     * en mora y sin cubrir, `overdue`; sin ninguna, `active` (RB-6).
     *
     * @param  list<Installment>  $installments
     */
    private function targetStatus(LayawayStatus $status, int $remaining, array $installments): ?LayawayStatus
    {
        if (! in_array($status, [LayawayStatus::Active, LayawayStatus::Overdue], true)) {
            return null;
        }

        if ($remaining <= $this->toleranceCents()) {
            return LayawayStatus::Completed;
        }

        $hasOverdue = false;

        foreach ($installments as $installment) {
            $hasOverdue = $hasOverdue || ($installment->status === InstallmentStatus::Overdue
                && $installment->paid_cents->cents < $installment->amount_cents->cents);
        }

        return match (true) {
            $hasOverdue && $status === LayawayStatus::Active => LayawayStatus::Overdue,
            ! $hasOverdue && $status === LayawayStatus::Overdue => LayawayStatus::Active,
            default => null,
        };
    }

    private function toleranceCents(): int
    {
        $cents = $this->settings->get(SettingKey::SettlementToleranceCents);

        return is_numeric($cents) ? (int) $cents : (int) SettingKey::SettlementToleranceCents->defaultValue();
    }
}
