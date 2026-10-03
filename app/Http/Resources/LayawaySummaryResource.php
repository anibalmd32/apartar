<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Layaway;
use App\Queries\BusinessBalance;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un apartado tal como lo ve su cliente en el portal: `LayawaySummary`
 * (`REQ-LAY-020`, `REQ-LAY-021` RB-3).
 *
 * El dinero sale de los balances **materializados** (`REQ-PAY-008` RB-1) y en
 * las dos monedas, con la tasa del negocio del apartado (`ProjectsMoney`, que
 * usa la del tenant fijado). Se nombra por su `code`, nunca por su `id`, y
 * no lleva nada del motivo de una cancelación (`REQ-LAY-009` RB-8).
 *
 * @property Layaway $resource
 */
final class LayawaySummaryResource extends JsonResource
{
    use ProjectsMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $layaway = $this->resource;

        return [
            'code' => $layaway->code,
            'status' => $layaway->status->value,
            'statusLabel' => $layaway->status->customerLabel(),
            'isTerminal' => $layaway->status->isTerminal(),
            'acceptsPayments' => $layaway->status->acceptsPayments(),
            'productName' => $layaway->product_name_snapshot,
            'quantity' => $layaway->quantity,
            'total' => $this->money($layaway->total_cents),
            'paid' => $this->money($layaway->paid_cents),
            'remaining' => $this->money($layaway->remaining_cents),
            'nextDueDate' => $layaway->next_due_date?->toDateString(),
            'nextDueDateLabel' => self::dayLabel($layaway->next_due_date),
            'overdueInstallments' => BusinessBalance::overdueCountOf($layaway),
        ];
    }

    /**
     * Un día del calendario del negocio, como se lee: «25 de septiembre».
     *
     * Las fechas de vencimiento son `DATE` (`T-039`): no tienen hora ni zona,
     * así que se formatean tal cual, sin convertir.
     */
    public static function dayLabel(?CarbonInterface $day): ?string
    {
        return $day?->locale('es')->isoFormat('D [de] MMMM');
    }
}
