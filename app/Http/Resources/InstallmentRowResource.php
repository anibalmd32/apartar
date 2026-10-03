<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Installment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila del cronograma del portal: `InstallmentRow` (`REQ-LAY-021` RB-4,
 * RB-5).
 *
 * ## Los días, en el calendario del negocio
 *
 * `daysUntilDue` se calcula **aquí**, con el «hoy» de la zona de la
 * organización que recibe el constructor, y no en el navegador: a las 23:30
 * de Caracas ya es el día siguiente en Madrid, y un cliente que mire desde
 * fuera vería «vence mañana» de una cuota que vence hoy. Es la misma regla que
 * decide la mora (`REQ-LAY-007` RB-1): días de calendario contra el «hoy» del
 * negocio. Negativo si ya venció.
 *
 * ## El tono
 *
 * RB-5: pagada (verde), vencida (rojo), vence pronto (ámbar), pendiente
 * (neutro). «Vencida» es **ya pasada su fecha** y sin cubrir, aunque esté
 * dentro de la gracia: para quien paga, el día 26 una cuota del 25 está
 * vencida, haya o no mora. «Pronto» es el umbral del primer recordatorio del
 * negocio (`reminder_offsets_days`, `REQ-NOT-002` RB-5), para que el portal y
 * el aviso nunca se contradigan.
 *
 * @property Installment $resource
 */
final class InstallmentRowResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(Installment $installment, private readonly CarbonImmutable $today, private readonly int $soonDays)
    {
        parent::__construct($installment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $installment = $this->resource;
        $due = CarbonImmutable::parse($installment->due_date->toDateString(), $this->today->getTimezone())->startOfDay();
        $daysUntilDue = (int) round($this->today->diffInDays($due, false));
        $covered = $installment->paid_cents->cents >= $installment->amount_cents->cents;

        return [
            'number' => $installment->number,
            'dueDate' => $due->toDateString(),
            'dueDateLabel' => LayawaySummaryResource::dayLabel($due),
            'amount' => $this->money($installment->amount_cents),
            'paid' => $this->money($installment->paid_cents),
            // SP-5 · «pagado $10 de $30»: lo que falta, nunca negativo.
            'pending' => $this->money(new Money(max(0, $installment->amount_cents->cents - $installment->paid_cents->cents))),
            'status' => $installment->status->value,
            'daysUntilDue' => $daysUntilDue,
            'tone' => match (true) {
                $covered || $installment->status === InstallmentStatus::Paid => 'paid',
                $daysUntilDue < 0 => 'overdue',
                $daysUntilDue <= $this->soonDays => 'soon',
                default => 'pending',
            },
        ];
    }
}
