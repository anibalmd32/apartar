<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Layaway\NextPayment;
use App\Domain\Payment\ReceiptsInReview;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Support\HumanDate;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property NextPayment $resource
 */
final class NextPaymentResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(NextPayment $next, private readonly ReceiptsInReview $inReview, private readonly DateTimeZone $timezone)
    {
        parent::__construct($next);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $next = $this->resource;
        $alternative = $next->alternative;
        $submittedAt = $this->inReview->lastSubmittedAt;

        return [
            'kind' => $next->kind->value,
            'installmentNumber' => $next->installmentNumber,
            'overdueInstallments' => $next->overdueInstallments,
            'amount' => $this->money($next->amount),
            'started' => $next->started,
            'covered' => $next->covered(),
            'alternative' => $alternative === null ? null : [
                'installmentNumber' => $alternative->installmentNumber,
                'amount' => $this->money($alternative->amount),
            ],
            'inReview' => $this->inReview->any() ? [
                'amount' => $this->money($this->inReview->amount),
                'count' => $this->inReview->count,
                'submittedOn' => $submittedAt === null ? null : HumanDate::dayMonth($submittedAt->setTimezone($this->timezone)),
            ] : null,
        ];
    }
}
