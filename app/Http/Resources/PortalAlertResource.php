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
            'actionUrl' => $this->actionUrl($alert),
        ];
    }

    private function actionUrl(PortalAlert $alert): string
    {
        return match ($alert->kind) {
            PortalAlertKind::DownPaymentWindow,
            PortalAlertKind::Overdue,
            PortalAlertKind::ReceiptRejected,
            PortalAlertKind::DueSoon => route('portal.receipts.create', ['code' => $alert->layawayCode]),
            PortalAlertKind::ReceiptPending => route('portal.layaways.show', ['code' => $alert->layawayCode]),
            PortalAlertKind::ClaimAvailable => route('portal.claim'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(PortalAlert $alert): array
    {
        return match ($alert->kind) {
            PortalAlertKind::DownPaymentWindow => [
                'hoursLeft' => intdiv((int) $alert->minutesLeft, 60),
                'minutesLeft' => (int) $alert->minutesLeft,
                'layawayCode' => $alert->layawayCode,
                'businessName' => $this->businessName($alert),
            ],
            PortalAlertKind::Overdue => [
                'count' => (int) $alert->count,
                'amount' => $this->moneyAcrossBusinesses($alert->amounts),
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
            PortalAlertKind::ClaimAvailable => [
                'businessName' => $this->businessName($alert),
            ],
        };
    }

    private function businessName(PortalAlert $alert): string
    {
        return $alert->organization instanceof Organization
            ? $alert->organization->name
            : throw new LogicException(sprintf('La alerta `%s` identifica siempre su negocio.', $alert->kind->value));
    }
}
