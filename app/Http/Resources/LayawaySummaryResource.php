<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\ImageSet;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Layaway;
use App\Models\Product;
use App\Queries\BusinessBalance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
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
            'nextAmount' => $this->nextAmount($layaway),
            'overdueDays' => $this->overdueDays($layaway),
            'imageUrl' => $this->imageUrl($layaway),
        ];
    }

    /**
     * @return array{cents: int, usd: string, ves: string|null}|null
     */
    private function nextAmount(Layaway $layaway): ?array
    {
        $cents = $layaway->getAttribute('next_pending_cents');

        return is_numeric($cents) && (int) $cents > 0 ? $this->money(new Money((int) $cents)) : null;
    }

    private function overdueDays(Layaway $layaway): ?int
    {
        $oldest = $layaway->getAttribute('oldest_overdue_date');

        if (! is_string($oldest) || $oldest === '') {
            return null;
        }

        $context = app(TenantContext::class);
        $timezone = $context->hasOrganization() ? $context->organization()->timezone : 'America/Caracas';
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return (int) CarbonImmutable::parse($oldest, $timezone)->startOfDay()->diffInDays($today);
    }

    private function imageUrl(Layaway $layaway): ?string
    {
        if (! $layaway->relationLoaded('product')) {
            return null;
        }

        $media = $layaway->product?->getFirstMedia(Product::IMAGES);

        return $media instanceof Media ? ImageSet::of($media)->thumb : null;
    }

    public static function dayLabel(?CarbonInterface $day): ?string
    {
        return $day?->locale('es')->isoFormat('D [de] MMMM');
    }
}
