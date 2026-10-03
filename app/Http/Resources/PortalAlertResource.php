<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Account\PortalAlertKind;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Organization;
use App\Queries\PortalAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Una alerta del portal en su forma de cable: la unión discriminada
 * `PortalAlert` (`REQ-LAY-023`).
 *
 * ## Con el tenant de su negocio
 *
 * Como cada bloque de la portada, la alerta de un negocio se compone con su
 * tenant fijado: el importe sale con **su** fuente de tasa (`REQ-LAY-020`
 * RB-7) y la fecha ya viene en **su** calendario. La alerta consolidada de
 * cuotas vencidas (RB-5) no tiene un negocio: cada parte se proyecta con la
 * tasa del suyo antes de sumar (`ProjectsMoney::moneyAcrossBusinesses()`).
 *
 * ## El CTA
 *
 * Cada alerta lleva a la acción que la resuelve (RB-7): hoy, al detalle del
 * apartado, donde están las cuentas de cobro y el importe sugerido. El botón
 * de subir comprobante llega con `T-059` y ese es el sitio al que apuntará.
 *
 * @property PortalAlert $resource
 */
final class PortalAlertResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(PortalAlert $alert)
    {
        parent::__construct($alert);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;

        return [
            'kind' => $alert->kind->value,
            ...$this->fields($alert),
            'actionUrl' => route('portal.layaways.show', ['code' => $alert->layawayCode]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(PortalAlert $alert): array
    {
        return match ($alert->kind) {
            PortalAlertKind::DownPaymentWindow => [
                'hoursLeft' => intdiv((int) $alert->minutesLeft, 60),
                // SP-2 · con menos de una hora, en minutos.
                'minutesLeft' => (int) $alert->minutesLeft,
                'layawayCode' => $alert->layawayCode,
                'businessName' => $this->businessName($alert),
            ],
            PortalAlertKind::Overdue => [
                'count' => (int) $alert->count,
                'amount' => $this->moneyAcrossBusinesses($alert->amounts),
                // RB-3 · varios negocios a la vez, ninguno nombrado.
                'businessName' => $alert->organization?->name,
            ],
            PortalAlertKind::ReceiptRejected => [
                'layawayCode' => $alert->layawayCode,
                'businessName' => $this->businessName($alert),
            ],
            PortalAlertKind::DueSoon => [
                'dueDate' => $alert->dueDate?->toDateString(),
                'dueDateLabel' => LayawaySummaryResource::dayLabel($alert->dueDate),
                'amount' => $this->moneyAcrossBusinesses($alert->amounts),
                'businessName' => $this->businessName($alert),
            ],
            PortalAlertKind::ReceiptPending => [
                'count' => (int) $alert->count,
            ],
            PortalAlertKind::ClaimAvailable => throw new LogicException('`claim_available` es de la sesión de cuenta (`T-076`, `T-077`), y esta alerta todavía no se construye.'),
        };
    }

    private function businessName(PortalAlert $alert): string
    {
        return $alert->organization instanceof Organization
            ? $alert->organization->name
            : throw new LogicException(sprintf('La alerta `%s` identifica siempre su negocio (REQ-LAY-023 RB-3).', $alert->kind->value));
    }
}
