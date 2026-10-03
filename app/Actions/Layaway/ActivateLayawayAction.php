<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\ScheduleAlreadyMaterializedException;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Activa un apartado al aprobarse su inicial y **materializa su cronograma**
 * (`REQ-LAY-004`).
 *
 * Es el momento en que el apartado se vuelve real: ya no es una reserva
 * esperando dinero, sino N cuotas con fecha que el cliente tiene que pagar.
 * El cálculo es de `ScheduleGenerator` (`T-041`), probado con quinientos
 * casos; aquí solo se escribe.
 *
 * Quien la llama es la aprobación de la inicial —`PaymentApplier`, H4—, dentro
 * de su transacción y con el tenant del apartado fijado.
 *
 * ## Una sola cosa, con el apartado bloqueado
 *
 * Transicionar a `active`, insertar las cuotas, comprobar la invariante y fijar
 * `next_due_date` van en **una transacción** con la fila del apartado bloqueada
 * desde el principio. Un cronograma insertado con el apartado todavía en
 * `pending_down_payment`, o un `active` sin cuotas, son estados que nadie sabe
 * leer, y separados existiría el instante —y el fallo— en que se ven.
 *
 * La transición va **primero** porque es la que escribe `activated_at`, y la
 * cuota 1 cuenta desde esa marca (RB-1, RB-3): ni desde `now()` ni desde la
 * creación del apartado. Se lee de la fila, que es lo que cruzará cualquier
 * informe con las fechas de las cuotas.
 *
 * ## Las fechas, en la zona del negocio
 *
 * `generate()` recibe la zona por parámetro precisamente para que no se coja
 * del ambiente (SP-7): la del apartado, que es la de la organización fijada.
 * Dos negocios en zonas distintas que activan en el mismo instante pueden
 * tener la primera cuota en días distintos, y es correcto.
 *
 * ## La invariante, otra vez, contra la base
 *
 * `T-041` probó `sum(cuotas) === financiado` sobre el dominio. Aquí se vuelve
 * a afirmar **leyendo las filas insertadas** contra `total_cents` y la inicial
 * congelada (RB-5): los céntimos se pierden en un *cast* o en una columna con
 * el tipo equivocado, y eso solo se ve en lo que llegó a MySQL. Si no cuadra,
 * se deshace todo.
 *
 * ## Financiado cero: dos pasos, no una décima celda
 *
 * SP-2 dice que el apartado «pasa directamente a `completed`», y la matriz de
 * `REQ-LAY-005` RB-1 no tiene `pending_down_payment → completed`. No se añade:
 * va `pending_down_payment → active → completed` en la misma transacción. La
 * mercancía quedó comprometida —el apartado estuvo activo, aunque fuera un
 * instante—, `activated_at` solo se escribe al salir de `pending_down_payment`
 * y un `completed` sin él se le pierde a todo informe que cruce por esa
 * columna, y así se emiten los dos eventos que H7 necesita. Sin cuotas y con
 * `next_due_date` en `null`.
 *
 * ## Dos garantías distintas
 *
 *  - **SP-6, una segunda llamada** sobre un apartado ya activo: la rechaza la
 *    máquina de estados, porque `active → active` no está en la matriz
 *    (`IllegalLayawayTransitionException`). No llega a insertar nada.
 *  - **SP-5, el reintento con una inserción a medias**: un apartado aún en
 *    `pending_down_payment` que ya tiene cuotas. Lo para
 *    `UNIQUE(organization_id, layaway_id, number)`, se deshace todo y sube
 *    `ScheduleAlreadyMaterializedException`.
 *
 * Ninguna cubre a la otra: la primera mira el estado, la segunda las filas.
 *
 * ## Lo que no hace
 *
 * No decide **cuándo** se activa —eso es de quien aprueba la inicial, H4— ni
 * toca el balance: `paid_cents` y `remaining_cents` son de
 * `LayawayBalanceRecalculator` (`T-057`). `next_due_date` lo escribe aquí
 * porque nace con el cronograma (RB-8) y el recalculador aún no existe;
 * `ActivateLayawayActionTest` se pone rojo el día que exista, para que la
 * escritura pase a él (`REQ-PAY-008` RB-7).
 */
final class ActivateLayawayAction
{
    /** El nombre que Laravel da al único de `installments` (migración de `T-039`). */
    private const SCHEDULE_INDEX = 'installments_organization_id_layaway_id_number_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayStateMachine $machine,
        private readonly ScheduleGenerator $generator,
    ) {}

    /**
     * Activa el apartado y devuelve el cronograma que materializó, vacío si
     * no había nada que financiar.
     *
     * Al volver, el modelo que se pasó lleva el estado, `activated_at` y
     * `next_due_date` nuevos.
     *
     * @throws IllegalLayawayTransitionException si el apartado no está esperando la inicial (SP-6)
     * @throws ScheduleAlreadyMaterializedException si ya tenía cuotas (SP-5)
     */
    public function handle(Layaway $layaway): AmortizationSchedule
    {
        try {
            return DB::transaction(function () use ($layaway): AmortizationSchedule {
                // Bloqueado desde el principio y por el global scope: el de
                // otra organización no existe para esta búsqueda. Se trabaja
                // sobre esta copia para que, si algo se deshace, el modelo de
                // quien llama no se quede contando un estado que no ocurrió.
                /** @var Layaway $locked */
                $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

                // SP-6 · `active → active` no está en la matriz. Y escribe
                // `activated_at`, de donde cuenta el cronograma.
                $this->machine->transition($locked, LayawayStatus::Active);

                $schedule = $this->generator->generate($locked->terms(), $this->activatedAt($locked), $this->timezone());

                foreach ($schedule->installments as $scheduled) {
                    $this->insert($locked, $scheduled);
                }

                $this->assertScheduleAddsUp($locked);

                // RB-8 · la primera **no pagada**, no la primera: recién
                // generadas son la misma, y dejan de serlo con el primer pago.
                Layaway::query()->whereKey($locked->getKey())->update([
                    'next_due_date' => $schedule->firstUnpaid()?->dueDateString(),
                ]);

                if ($schedule->isEmpty()) {
                    // SP-2 · financiado cero: el segundo paso, en la misma
                    // transacción.
                    $this->machine->transition($locked, LayawayStatus::Completed);
                }

                $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su activación.');
                $layaway->setRawAttributes($fresh->getAttributes(), sync: true);

                return $schedule;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::SCHEDULE_INDEX)) {
                throw $exception;
            }

            throw new ScheduleAlreadyMaterializedException($layaway->getKey(), $exception);
        }
    }

    private function insert(Layaway $layaway, ScheduledInstallment $scheduled): void
    {
        $installment = new Installment;

        // Sin `status`: nace `pending`, que es el valor por defecto de la
        // columna (`T-039`), y sin `organization_id`, que lo pone
        // `BelongsToOrganization` desde el tenant fijado.
        $installment->forceFill([
            'layaway_id' => $layaway->getKey(),
            'number' => $scheduled->number,
            'due_date' => $scheduled->dueDateString(),
            'amount_cents' => $scheduled->amount,
        ])->save();
    }

    /**
     * RB-5 contra lo que llegó a MySQL: la suma de las filas, leída con `SUM`,
     * frente a `total_cents` menos la inicial congelada.
     */
    private function assertScheduleAddsUp(Layaway $layaway): void
    {
        $stored = (int) Installment::query()->where('layaway_id', $layaway->getKey())->sum('amount_cents');
        $financed = $layaway->total_cents->cents - $layaway->terms()->totalDownPayment()->cents;

        if ($stored !== $financed) {
            throw new LogicException(sprintf(
                'Las cuotas del apartado %s suman %d céntimos en la base y lo financiado es %d (total %d menos la inicial). '
                .'sum(installments.amount_cents) === total − inicial es una invariante dura (REQ-LAY-004 RB-5): no se activa nada.',
                $layaway->getKey(),
                $stored,
                $financed,
                $layaway->total_cents->cents,
            ));
        }
    }

    /**
     * La marca que acaba de escribir la máquina, tal como quedó en la fila.
     */
    private function activatedAt(Layaway $layaway): CarbonImmutable
    {
        $activatedAt = $layaway->activated_at
            ?? throw new LogicException('La máquina de estados activó el apartado sin escribir «activated_at».');

        return CarbonImmutable::instance($activatedAt);
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->context->organization()->timezone);
    }
}
