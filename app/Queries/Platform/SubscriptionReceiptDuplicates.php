<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceipts;
use App\Models\SubscriptionReceipt;

final class SubscriptionReceiptDuplicates
{
    public function criterionFor(string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        return DuplicateReceipts::criterionFor(SubscriptionReceipt::query()->withoutTenantScope(), $fileHash, $bank, $reference);
    }
}
