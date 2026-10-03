<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\LayawayReceipts;
use App\Models\Layaway;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * El negocio cancela un apartado (`REQ-LAY-009`).
 *
 * ## Una transacción, en este orden
 *
 *  1. **El producto, antes que el apartado** (`REQ-LAY-003` RB-8). La máquina
 *     ya lo bloquea así al cancelar, pero esta acción coge el apartado
 *     **antes** de llamarla —para comprobar el estado y rechazar sus
 *     comprobantes—, y con el apartado ya cogido el orden quedaría invertido:
 *     dos cancelaciones contra una reserva del mismo producto se bloquearían
 *     en cruz.
 *  2. El apartado, con `FOR UPDATE`, y su estado comprobado **bajo el
 *     bloqueo** (RB-3, SP-1, SP-4): un `completed` o un `cancelled` no se
 *     cancela, y una aprobación concurrente que lo completó gana.
 *  3. Sus comprobantes en revisión, rechazados con `layaway_cancelled` (SP-3):
 *     no quedan huérfanos en la bandeja. Lo hace `LayawayReceipts`, que hoy no
 *     tiene tabla que consultar (`T-054`).
 *  4. La transición, que libera el stock y el cupo y emite `LayawayCancelled`
 *     (RB-4) —el aviso al cliente cuelga de ese evento (`T-083`)—.
 *  5. El motivo, en `cancellation_reason`, y la auditoría (RB-2).
 *
 * `paid_cents` y las cuotas quedan como estaban (RB-5, RB-6): no hay
 * reembolso ni penalización, y lo pagado sigue en el historial como constancia.
 *
 * El motivo es **interno**: lo ve el negocio y nunca el cliente (RB-8). El
 * portal no lee esa columna.
 *
 * No pregunta por la suspensión de la organización (RB-9): cancelar es
 * gestión de la cobranza.
 */
final class CancelLayawayAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly ReleaseStockAction $stock,
        private readonly LayawayStateMachine $machine,
        private readonly LayawayReceipts $receipts,
    ) {}

    /**
     * @throws AuthorizationException si quien cancela no gestiona la cobranza del negocio
     * @throws InvalidArgumentException si el motivo no tiene entre 10 y 500 caracteres (RB-2)
     * @throws IllegalLayawayTransitionException si el apartado ya es terminal (SP-1)
     */
    public function handle(Layaway $layaway, User $actor, string $reason): Layaway
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $reason = new StaffReason($reason);

        return DB::transaction(function () use ($layaway, $actor, $reason): Layaway {
            $this->stock->lockProductOf($layaway);

            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo(LayawayStatus::Cancelled)) {
                throw new IllegalLayawayTransitionException($from, LayawayStatus::Cancelled, $locked->getKey());
            }

            $rejected = $this->receipts->rejectPendingOnCancellation($locked);

            $this->machine->transition($locked, LayawayStatus::Cancelled);
            $locked->forceFill(['cancellation_reason' => $reason->text])->save();

            $this->audit(
                AuditEvent::LayawayCancelled,
                subject: $locked,
                actor: $actor,
                before: ['status' => $from->value],
                after: [
                    'status' => LayawayStatus::Cancelled->value,
                    'cancellation_reason' => $reason->text,
                ] + ($rejected > 0 ? ['receipts_rejected' => $rejected] : []),
            );

            $layaway->setRawAttributes($locked->getAttributes(), sync: true);

            return $layaway;
        });
    }
}
