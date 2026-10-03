<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El negocio marca que entregó el producto de un apartado pagado
 * (`REQ-LAY-010`).
 *
 * **No es una transición** (RB-3): el estado sigue siendo `completed`, el
 * cupo del plan no se mueve y la máquina de estados no interviene. Lo único
 * que se escribe es `delivered_at`, una sola vez (RB-2): una segunda
 * pulsación encuentra la marca puesta y no hace nada —ni columna ni
 * auditoría— (SP-2). Se comprueba bajo el bloqueo del apartado, para que dos
 * pulsaciones a la vez no escriban dos fechas ni dos entradas.
 *
 * Solo un `completed` se entrega (RB-1, SP-1, SP-3). El aviso al cliente
 * (RB-4) es de `T-083`, que no tiene aquí evento del que colgarse: esta
 * acción no transiciona.
 */
final class MarkDeliveredAction
{
    use LogsSensitiveActivity;

    /**
     * @throws AuthorizationException si quien marca no gestiona la cobranza del negocio
     * @throws LayawayNotDeliverableException si el apartado no está `completed`
     */
    public function handle(Layaway $layaway, User $actor): Layaway
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        return DB::transaction(function () use ($layaway, $actor): Layaway {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== LayawayStatus::Completed) {
                throw new LayawayNotDeliverableException($locked->status);
            }

            if ($locked->delivered_at === null) {
                $locked->forceFill(['delivered_at' => CarbonImmutable::now()])->save();

                $this->audit(
                    AuditEvent::LayawayDelivered,
                    subject: $locked,
                    actor: $actor,
                    after: ['delivered_at' => $locked->delivered_at?->toIso8601String()],
                );
            }

            $layaway->setRawAttributes($locked->getAttributes(), sync: true);

            return $layaway;
        });
    }
}
