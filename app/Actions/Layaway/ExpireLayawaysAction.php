<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\ExpirationOutcome;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\LayawayReceipts;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expira los apartados de la organización fijada cuya inicial no llegó a
 * tiempo (`REQ-LAY-006`).
 *
 * La llama `ExpireLayawaysJob` una vez por organización, con el tenant fijado
 * por `RunsForEveryOrganization` (RB-6, SP-7): esta acción no enumera
 * negocios ni sale de su tenant, y todo lo que lee va por el global scope.
 *
 * ## Qué expira
 *
 * Un apartado en `pending_down_payment` con `expires_at` **anterior** al
 * instante de la pasada (RB-1) y sin comprobante en revisión ni aprobado
 * (RB-2). El plazo es el que se congeló al crearlo con la ventana de **su**
 * organización (RB-7, SP-5), o el de la prórroga si la tuvo (RB-3): aquí no
 * se vuelve a leer ningún ajuste. Un apartado ya `active` con el plazo pasado
 * no entra (SP-6).
 *
 * Expirar es una transición, y la hace la máquina: devuelve el stock con
 * `ReleaseStockAction`, idempotente por `stock_released_at` (RB-4), libera el
 * cupo del plan y emite `LayawayExpired` después del commit. Nada de eso se
 * repite aquí.
 *
 * ## Un apartado, una transacción, y el producto primero
 *
 * Entre la lectura del lote y el paso a `expired` pueden pasar cosas: que se
 * apruebe la inicial, que llegue un comprobante, que un rechazo dé la
 * prórroga. Así que cada apartado se **vuelve a comprobar bajo el bloqueo**
 * —estado, plazo y comprobantes— antes de transicionar.
 *
 * Y el bloqueo va en el orden canónico de `REQ-LAY-003` RB-8: **el producto
 * antes que el apartado**. La máquina lo pide en ese orden al devolver el
 * stock, y bloquear aquí el apartado primero lo invertiría: dos transacciones
 * con órdenes distintos sobre las mismas filas se abrazan, y eso no sale en
 * la suite, sale en producción. `ExpireLayawaysActionTest` lo afirma con
 * `assertLocksTheProductFirst()`.
 *
 * Una transacción por apartado y no una por lote: un fallo en uno no deshace
 * los demás, y el bloqueo del producto no se retiene más de lo necesario.
 *
 * ## Lo que no hace
 *
 * **No notifica.** El aviso de 12 h antes y el de la expiración (RB-5) son de
 * `T-083`: el primero es `down_payment_reminder` de `DispatchDueRemindersAction`
 * (`REQ-NOT-002` RB-6), con su registro de idempotencia, y el segundo un
 * listener de `LayawayExpired`. `ExpireLayawaysActionTest` se pone rojo el día
 * que exista `DispatchDueRemindersAction`.
 *
 * **No pregunta por la suspensión** (RB-8): la expiración es parte de la
 * operación del apartado, y la liberación de stock tampoco pregunta.
 */
final class ExpireLayawaysAction
{
    /** Apartados por consulta al recorrer los vencidos. */
    private const CHUNK = 100;

    public function __construct(
        private readonly LayawayStateMachine $machine,
        private readonly ReleaseStockAction $stock,
        private readonly LayawayReceipts $receipts,
    ) {}

    /**
     * Una pasada sobre los apartados de la organización fijada.
     *
     * `$now` llega de fuera para que todas las organizaciones de una misma
     * ejecución del job se evalúen contra el mismo instante.
     */
    public function handle(CarbonImmutable $now): ExpirationOutcome
    {
        $outcome = new ExpirationOutcome;

        Layaway::query()
            ->where('status', LayawayStatus::PendingDownPayment)
            ->where('expires_at', '<', $now)
            ->chunkById(self::CHUNK, function (Collection $layaways) use ($now, &$outcome): void {
                foreach ($layaways as $layaway) {
                    $outcome = $this->expire($layaway, $now, $outcome);
                }
            });

        return $outcome;
    }

    private function expire(Layaway $layaway, CarbonImmutable $now, ExpirationOutcome $outcome): ExpirationOutcome
    {
        return DB::transaction(function () use ($layaway, $now, $outcome): ExpirationOutcome {
            // RB-8 de `REQ-LAY-003` · el producto antes que el apartado.
            $this->stock->lockProductOf($layaway);

            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Layaway || ! $this->stillExpirable($locked, $now)) {
                return $outcome;
            }

            // RB-2 · el cliente pagó a tiempo y la demora es del negocio.
            if ($this->receipts->holdExpiration($locked)) {
                return $outcome->withHeld();
            }

            $this->machine->transition($locked, LayawayStatus::Expired);

            return $outcome->withExpired();
        });
    }

    /**
     * Lo mismo que filtró la consulta, otra vez y ya bajo el bloqueo.
     */
    private function stillExpirable(Layaway $layaway, CarbonImmutable $now): bool
    {
        return $layaway->status === LayawayStatus::PendingDownPayment
            && $layaway->expires_at !== null
            && $layaway->expires_at->lessThan($now);
    }
}
