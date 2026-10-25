<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;

trait CreatesReceipts
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function receiptFor(Layaway $layaway, ReceiptStatus $status = ReceiptStatus::PendingReview, array $attributes = []): Receipt
    {
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return app(TenantContext::class)->runFor($organization, fn (): Receipt => Receipt::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'status' => $status,
            ...$attributes,
        ]));
    }
}
