<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Actions\Layaway\ReleaseStockAction;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayBecameOverdue;
use App\Events\Layaway\LayawayCancelled;
use App\Events\Layaway\LayawayCaughtUp;
use App\Events\Layaway\LayawayCompleted;
use App\Events\Layaway\LayawayExpired;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * El único sitio donde un apartado cambia de estado (`REQ-LAY-005`).
 *
 * `transition()` hace **cuatro cosas en una transacción**, y que sean una es
 * el motivo de que exista:
 *
 *  1. **bloquea la fila** con `lockForUpdate` y valida contra el estado
 *     **leído bajo el bloqueo**, no contra el del modelo que llega (SP-3): dos
 *     transiciones simultáneas se serializan, y la segunda ve lo que dejó la
 *     primera;
 *  2. **escribe el estado y su marca de tiempo** (RB-7);
 *  3. **ajusta el cupo** `active_layaways` si la transición cruza la frontera
 *     de `countsTowardPlanLimit()` (RB-6), y **devuelve el stock** si el
 *     destino es `expired` o `cancelled` (`REQ-LAY-003` RB-2, desde `T-044`);
 *  4. **emite el evento**, que se despacha después del commit (RB-7, SP-6).
 *
 * Repartidas entre quien llama, existiría un instante —y un fallo— en que el
 * estado dice `completed` y el cupo sigue ocupado, o `activated_at` está
 * escrito y el estado no. Juntas, o pasan las cuatro o ninguna.
 *
 * ## El stock viaja con la transición, y el producto se bloquea antes
 *
 * La liberación va aquí por lo mismo que el cupo: el job de expiración
 * (`T-047`) y la cancelación (`T-053`) cambian el estado **llamando a esta
 * máquina**, y si tuvieran que acordarse de devolver la unidad, uno de los dos
 * acabaría sin hacerlo. Es la única dependencia de este archivo hacia una
 * `Action`, y es deliberada: `ReleaseStockAction` es la pieza que la spec
 * nombra, con su idempotencia, y duplicarla aquí sería tener dos.
 *
 * Y cambia el orden de los bloqueos: cuando la transición va a devolver stock,
 * **el producto se bloquea antes que el apartado** (`REQ-LAY-003` RB-8). Es el
 * orden de la creación del apartado —producto, cupo, cliente, apartado—, y
 * con dos órdenes distintos sobre las mismas filas dos transacciones se
 * abrazan. `StockLockOrderTest` lo afirma sobre el registro de consultas.
 *
 * ## Lo que no pregunta
 *
 * **Ni la suscripción ni el estado de la organización** (RB-9,
 * `REQ-SUB-007` RB-3): que un negocio no le haya pagado a la plataforma no
 * puede impedir que su cliente termine de pagar su nevera. Si alguna vez
 * aparece aquí una lectura de `Subscription`, la comprobación está en el sitio
 * equivocado.
 *
 * **Ni el saldo ni las cuotas.** Cuándo se completa un apartado (RB-5), cuándo
 * entra en mora (`REQ-LAY-007`) o cuándo sale de ella (RB-4) lo deciden quienes
 * tienen esos datos —`LayawayBalanceRecalculator`, el job de mora, la
 * activación— y llaman aquí. La máquina decide lo único que es suyo: si el
 * paso es legal, y qué viaja con él.
 *
 * Se llama con el tenant fijado, como toda consulta del tenant: un apartado de
 * otra organización no existe para esta búsqueda, y transicionarlo lanza
 * `ModelNotFoundException` sin tocarlo.
 */
final class LayawayStateMachine
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly ReleaseStockAction $stock,
    ) {}

    /**
     * Lleva el apartado a `$to`, o lanza si la matriz de RB-1 no lo admite.
     *
     * Al volver, el modelo que se pasó lleva el estado y las marcas nuevas.
     *
     * @throws IllegalLayawayTransitionException
     */
    public function transition(Layaway $layaway, LayawayStatus $to): void
    {
        DB::transaction(function () use ($layaway, $to): void {
            if ($to->releasesStock()) {
                // RB-8 de `REQ-LAY-003` · el producto antes que el apartado.
                // Se bloquea aunque la transición resulte ilegal: es barato, y
                // decidirlo después obligaría a bloquear en el otro orden.
                $this->stock->lockProductOf($layaway);
            }

            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new IllegalLayawayTransitionException($from, $to, $locked->getKey());
            }

            $now = CarbonImmutable::now();

            // Por el *query builder* y no por `save()`: es la única escritura
            // del estado que la guarda del modelo no rechaza, y la única que
            // admite `LayawayStatusWritesTest` (RB-2). La columna va escrita
            // aquí, en la llamada, para que ese test la reconozca.
            Layaway::query()->whereKey($locked->getKey())->update(['status' => $to->value] + $this->marksFor($from, $to, $now));

            if ($to->releasesStock()) {
                // RB-2 · expirar y cancelar devuelven la unidad; completar no.
                $this->stock->handle($locked);
            }

            $delta = (int) $to->countsTowardPlanLimit() - (int) $from->countsTowardPlanLimit();

            if ($delta !== 0) {
                $this->limits->adjust(UsageMetric::ActiveLayaways, $delta);
            }

            // El modelo de quien llama queda como la fila, sin pasar por la
            // guarda: los atributos se copian ya limpios.
            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su transición.');
            $layaway->setRawAttributes($fresh->getAttributes(), sync: true);

            event($this->eventFor($locked, $from, $to, $now));
        });
    }

    /**
     * La marca de tiempo de cada transición que tiene columna propia.
     *
     * `activated_at` se escribe **solo al salir de `pending_down_payment`**:
     * la vuelta desde `overdue` (RB-4) no es una activación, y reescribirla
     * movería la fecha desde la que cuenta el cronograma. Las transiciones sin
     * columna en [05 §4] —entrar en mora, salir de ella y expirar— dejan su
     * instante en `updated_at` y en el evento (`occurredAt`): `expires_at` es
     * el **plazo** de la inicial, no el momento en que expiró, y no se toca.
     *
     * @return array<'activated_at'|'completed_at'|'cancelled_at', CarbonImmutable>
     */
    private function marksFor(LayawayStatus $from, LayawayStatus $to, CarbonImmutable $now): array
    {
        return match ($to) {
            LayawayStatus::Active => $from === LayawayStatus::PendingDownPayment ? ['activated_at' => $now] : [],
            LayawayStatus::Completed => ['completed_at' => $now],
            LayawayStatus::Cancelled => ['cancelled_at' => $now],
            LayawayStatus::Overdue, LayawayStatus::Expired => [],
            LayawayStatus::PendingDownPayment => throw new LogicException('Ninguna transición lleva a «pending_down_payment».'),
        };
    }

    private function eventFor(Layaway $layaway, LayawayStatus $from, LayawayStatus $to, CarbonImmutable $now): LayawayTransitioned
    {
        $arguments = [(int) $layaway->getKey(), (int) $layaway->getAttribute('organization_id'), $from, $to, $now];

        return match ($to) {
            LayawayStatus::Active => $from === LayawayStatus::Overdue
                ? new LayawayCaughtUp(...$arguments)
                : new LayawayActivated(...$arguments),
            LayawayStatus::Overdue => new LayawayBecameOverdue(...$arguments),
            LayawayStatus::Completed => new LayawayCompleted(...$arguments),
            LayawayStatus::Cancelled => new LayawayCancelled(...$arguments),
            LayawayStatus::Expired => new LayawayExpired(...$arguments),
            LayawayStatus::PendingDownPayment => throw new LogicException('Ninguna transición lleva a «pending_down_payment».'),
        };
    }
}
