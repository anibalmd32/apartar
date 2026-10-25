<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Layaway\DueCalendar;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Money\Money;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Installment $resource
 */
final class InstallmentRowResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(Installment $installment, private readonly DueCalendar $calendar)
    {
        parent::__construct($installment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $installment = $this->resource;
        $due = $this->calendar->day($installment->due_date);
        $daysUntilDue = $this->calendar->daysUntil($due);
        $covered = $installment->paid_cents->cents >= $installment->amount_cents->cents;

        return [
            'number' => $installment->number,
            'dueDate' => $due->toDateString(),
            'dueDateLabel' => LayawaySummaryResource::dayLabel($due),
            'amount' => $this->money($installment->amount_cents),
            'paid' => $this->money($installment->paid_cents),
            'pending' => $this->money(new Money(max(0, $installment->amount_cents->cents - $installment->paid_cents->cents))),
            'status' => $installment->status->value,
            'daysUntilDue' => $daysUntilDue,
            'tone' => match (true) {
                $covered || $installment->status === InstallmentStatus::Paid => 'paid',
                $this->calendar->isPastDue($due) => 'overdue',
                $this->calendar->isDueSoon($due) => 'soon',
                default => 'pending',
            },
        ];
    }
}
