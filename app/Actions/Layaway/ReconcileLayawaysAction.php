<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\BalanceDiscrepancy;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\ReconciliationReport;
use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La reconciliación de **una** organización, la fijada (`REQ-PAY-008` RB-4,
 * `T-064`). Quien recorre las organizaciones es el job; esto compara lo
 * materializado con lo derivado, lo informa, y con `--fix` lo corrige.
 *
 * ## Qué se compara
 *
 *  1. **El saldo de cada apartado** —de todos los estados, también los
 *     terminales (SP-6)— contra lo que derivan sus cuotas y su inicial, más
 *     la transición que tocaría y lo que dicen sus pagos:
 *     `LayawayBalanceRecalculator::diffMany()`, la misma comparación que
 *     `diff()` hecha por lotes (SP-4).
 *  2. **Lo pagado de cada cuota** contra lo que le reparten los pagos
 *     (`payment_allocations`). Es la otra cara de la primera invariante: si
 *     las cuotas no cuentan lo que los pagos repartieron, el saldo —que se
 *     deriva de las cuotas— está mal aunque cuadre consigo mismo.
 *  3. **El faltante de cada producto** contra sus unidades apartadas
 *     (`stock_shortfall <= reservadas`, `T-044`). `Product` lo recorta al
 *     leerlo, así que la pantalla enseñaría el número bueno con el guardado
 *     mal: solo esto puede verlo.
 *
 * Cada lote hace las mismas consultas tenga los apartados que tenga: el lote,
 * sus cuotas, lo pagado y lo repartido, agrupados.
 *
 * ## Qué corrige `--fix`
 *
 * Con la fila bloqueada —y **saltándola** si otra operación la tiene, para la
 * pasada siguiente (SP-5)—: reconstruye lo pagado de cada cuota desde sus
 * asignaciones (los pagos no se tocan, SP-1), recalcula el apartado con
 * `LayawayBalanceRecalculator::recalculate()` —que es quien escribe el saldo
 * y pide la transición— y lo audita. Si después los pagos y las cuotas
 * siguen sin contar lo mismo, no hay nada derivable que escribir: queda
 * `unresolved` para una persona. El faltante se acota a las reservadas.
 */
final class ReconcileLayawaysAction
{
    use LogsSensitiveActivity;

    public const BATCH = 200;

    /** El proceso, en la descripción de la auditoría (`REQ-BOF-005` RB-7). */
    private const PROCESS = 'reconciliación de saldos (layaways:reconcile)';

    public function __construct(
        private readonly LayawayBalanceRecalculator $balance,
        private readonly DetectOverdueAction $overdue,
    ) {}

    public function handle(bool $fix, CarbonImmutable $now): ReconciliationReport
    {
        $report = new ReconciliationReport;

        Layaway::query()->chunkById(self::BATCH, function (Collection $batch) use ($fix, $now, $report): void {
            $report->layaways += $batch->count();

            // El lote se lee **dentro de una transacción**: con `REPEATABLE
            // READ` —el aislamiento de MySQL por defecto— todas sus lecturas
            // ven la misma foto. Fuera de ella, una aprobación que confirmara
            // entre la consulta de las cuotas y la de los pagos haría ver un
            // descuadre que no existe, y la pantalla de Salud lo enseñaría
            // como el problema más grave de la plataforma (SP-5).
            [$layaways, $discrepancies, $drift] = DB::transaction(function () use ($batch): array {
                /** @var list<Layaway> $layaways */
                $layaways = Layaway::query()->whereKey($batch->modelKeys())->orderBy('id')->get()->all();

                return [$layaways, $this->balance->diffMany($layaways), $this->installmentDrift($layaways)];
            });

            foreach ($layaways as $layaway) {
                $id = (int) $layaway->getKey();

                if (! isset($discrepancies[$id]) && ! isset($drift[$id])) {
                    continue;
                }

                $report->add($this->layawayEntry($layaway, $discrepancies[$id] ?? null, $drift[$id] ?? []) + [
                    'outcome' => $fix ? $this->fixLayaway($layaway, $now) : 'reported',
                ]);
            }
        });

        // También los archivados: el faltante es de sus apartados, que siguen.
        Product::query()->withTrashed()->withReservedUnits()->where('stock_shortfall', '>', 0)->chunkById(self::BATCH, function (Collection $batch) use ($fix, $report): void {
            /** @var Product $product */
            foreach ($batch as $product) {
                $report->products++;
                $reserved = $product->reservedUnits();
                $stored = (int) $product->getAttribute('stock_shortfall');

                if ($stored <= $reserved) {
                    continue;
                }

                $report->add([
                    'type' => 'product',
                    'organization_id' => (int) $product->organization_id,
                    'id' => (int) $product->getKey(),
                    'stock_shortfall' => $stored,
                    'reserved_units' => $reserved,
                    'outcome' => $fix ? $this->fixProduct($product) : 'reported',
                ]);
            }
        });

        return $report;
    }

    /**
     * Las cuotas cuyo `paid_cents` no es lo que les reparten los pagos, por
     * apartado. Dos consultas por lote.
     *
     * @param  list<Layaway>  $layaways
     * @return array<int, list<array{number: int, stored: int, allocated: int}>>
     */
    private function installmentDrift(array $layaways): array
    {
        $installments = Installment::query()
            ->whereIn('layaway_id', array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways))
            ->orderBy('number')
            ->get();

        $allocated = $this->allocatedTo($installments->modelKeys());
        $drift = [];

        foreach ($installments as $installment) {
            $cents = $allocated[(int) $installment->getKey()] ?? 0;

            if ($cents !== $installment->paid_cents->cents) {
                $drift[(int) $installment->layaway_id][] = ['number' => $installment->number, 'stored' => $installment->paid_cents->cents, 'allocated' => $cents];
            }
        }

        return $drift;
    }

    /**
     * Lo que los pagos reparten a cada cuota, en una consulta agrupada.
     *
     * @param  array<int, mixed>  $installmentIds
     * @return array<int, int>
     */
    private function allocatedTo(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        return array_map('intval', PaymentAllocation::query()
            ->whereIn('installment_id', $installmentIds)
            ->groupBy('installment_id')
            ->selectRaw('installment_id, SUM(amount_cents) AS cents')
            ->pluck('cents', 'installment_id')
            ->all());
    }

    /**
     * @param  list<array{number: int, stored: int, allocated: int}>  $drift
     * @return array<string, mixed>
     */
    private function layawayEntry(Layaway $layaway, ?BalanceDiscrepancy $discrepancy, array $drift): array
    {
        return [
            'type' => 'layaway',
            'organization_id' => (int) $layaway->organization_id,
            'id' => (int) $layaway->getKey(),
            'code' => $layaway->code,
            'status' => $layaway->status->value,
            'fields' => $discrepancy === null ? [] : $discrepancy->fields,
            'paid_from_payments' => $discrepancy?->paidFromPayments,
            'paid_from_schedule' => $discrepancy?->paidFromSchedule,
            'overpaid_cents' => $discrepancy === null ? 0 : $discrepancy->overpaidCents,
            'installments' => $drift,
        ];
    }

    /**
     * Corrige un apartado bajo su bloqueo, o lo salta si otro lo tiene.
     *
     * Un fallo en uno —un deadlock, una transición que la máquina rechaza— no
     * para la pasada: queda `failed` en el informe, en el log de errores, y
     * los demás se corrigen. Si no, una fila rota detendría todo `--fix`
     * posterior a ella.
     */
    private function fixLayaway(Layaway $layaway, CarbonImmutable $now): string
    {
        try {
            return $this->correctLayaway($layaway, $now);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }
    }

    /**
     * Lo que hace `--fix` con un apartado (ver el docblock de la clase).
     *
     * **Un terminal no se reconstruye**: sus cuotas ni su estado se tocan
     * (`REQ-LAY-007` SP-4) —una cuota de un apartado cancelado que pasara a
     * `overdue` no dice nada verdadero—, y un completado con cuotas que no
     * cuadran con sus pagos tampoco: se recalculan sus columnas, y si las
     * cuotas descuadran, queda `unresolved` para una persona.
     */
    private function correctLayaway(Layaway $layaway, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($layaway, $now): string {
            $locked = Layaway::query()->whereKey($layaway->getKey())->lock('for update skip locked')->first();

            if (! $locked instanceof Layaway) {
                return 'skipped';
            }

            /** @var list<Installment> $installments */
            $installments = $locked->installments()->lockForUpdate()->get()->all();
            $before = $this->stateOf($locked, $installments);
            $allocated = $this->allocatedTo(array_map(fn (Installment $installment): int => (int) $installment->getKey(), $installments));
            $collecting = in_array($locked->status, [LayawayStatus::Active, LayawayStatus::Overdue], true);
            $drifted = false;

            foreach ($installments as $installment) {
                $cents = $allocated[(int) $installment->getKey()] ?? 0;

                if ($cents === $installment->paid_cents->cents) {
                    continue;
                }

                $drifted = true;

                if (! $collecting) {
                    continue;
                }

                $paid = new Money($cents);
                $status = InstallmentStatus::resolve($installment->amount_cents, $paid, $this->overdue->isPastGrace($installment, $now));

                $installment->forceFill([
                    'paid_cents' => $paid,
                    'status' => $status,
                    'paid_at' => $status === InstallmentStatus::Paid ? ($installment->paid_at ?? $this->coveredAt((int) $installment->getKey(), $now)) : null,
                ])->save();
            }

            // Al volver, `$locked` lleva el saldo y el estado nuevos.
            $this->balance->recalculate($locked);
            $after = $this->stateOf($locked, $locked->installments()->get()->all());

            if ($after !== $before) {
                $this->audit(AuditEvent::ReconciliationCorrected, subject: $locked, before: $before, after: $after, process: self::PROCESS);
            }

            $remaining = $this->balance->diff($locked);
            $stillWrong = $remaining?->ledgerMismatch() === true || ($remaining !== null && $remaining->overpaidCents > 0);

            return $stillWrong || ($drifted && ! $collecting) ? 'unresolved' : 'fixed';
        });
    }

    /**
     * Cuándo quedó cubierta una cuota que la reconstrucción da por pagada: el
     * día del pago más reciente que le reparte algo —cuándo llegó el dinero—,
     * no la hora de la reconciliación.
     */
    private function coveredAt(int $installmentId, CarbonImmutable $now): CarbonImmutable
    {
        $settled = Payment::query()
            ->whereIn('id', PaymentAllocation::query()->where('installment_id', $installmentId)->select('payment_id'))
            ->max('settled_at');

        return is_string($settled) ? CarbonImmutable::parse($settled) : $now;
    }

    /**
     * Acota el faltante a las reservadas, bajo el bloqueo del producto.
     */
    private function fixProduct(Product $product): string
    {
        try {
            return $this->correctProduct($product);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }
    }

    private function correctProduct(Product $product): string
    {
        return DB::transaction(function () use ($product): string {
            $locked = Product::query()->withTrashed()->whereKey($product->getKey())->lock('for update skip locked')->first();

            if (! $locked instanceof Product) {
                return 'skipped';
            }

            $reserved = $locked->reservedUnits();
            $stored = (int) $locked->getAttribute('stock_shortfall');

            if ($stored > $reserved) {
                // Por el *query builder*, como la reserva y la liberación: no es
                // una edición del negocio, y no debe mover `updated_at` ni la
                // marca de quién lo modificó (`REQ-BOF-020` SP-7).
                Product::query()->withTrashed()->whereKey($locked->getKey())->toBase()->update(['stock_shortfall' => $reserved]);

                $this->audit(
                    AuditEvent::ReconciliationCorrected,
                    subject: $locked,
                    before: ['stock_shortfall' => $stored],
                    after: ['stock_shortfall' => $reserved, 'reserved_units' => $reserved],
                    process: self::PROCESS,
                );
            }

            return 'fixed';
        });
    }

    /**
     * Lo que la auditoría compara: el saldo, el estado y lo pagado de cada
     * cuota.
     *
     * @param  list<Installment>  $installments
     * @return array<string, mixed>
     */
    private function stateOf(Layaway $layaway, array $installments): array
    {
        $paid = [];

        foreach ($installments as $installment) {
            $paid['cuota_'.$installment->number] = $installment->paid_cents->cents;
        }

        return [
            'paid_cents' => $layaway->paid_cents->cents,
            'remaining_cents' => $layaway->remaining_cents->cents,
            'next_due_date' => $layaway->next_due_date?->toDateString(),
            'status' => $layaway->status->value,
            'installments_paid_cents' => $paid,
        ];
    }
}
