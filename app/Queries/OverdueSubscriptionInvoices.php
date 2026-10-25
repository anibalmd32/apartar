<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Subscription\InvoiceStatus;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class OverdueSubscriptionInvoices
{
    /**
     * @return Collection<int, SubscriptionInvoice>
     */
    public function lockedAt(CarbonImmutable $now, string $timezone): Collection
    {
        return $this->query($now, $timezone)
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function outstandingCents(CarbonImmutable $now, string $timezone): int
    {
        return (int) $this->query($now, $timezone)->sum(DB::raw('GREATEST(amount_cents - paid_cents, 0)'));
    }

    public function anyBesides(SubscriptionInvoice $except, CarbonImmutable $now, string $timezone): bool
    {
        return $this->query($now, $timezone)->whereKeyNot($except->getKey())->lockForUpdate()->exists();
    }

    /**
     * @return Builder<SubscriptionInvoice>
     */
    private function query(CarbonImmutable $now, string $timezone): Builder
    {
        $today = $now->setTimezone($timezone)->startOfDay();

        return SubscriptionInvoice::query()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::UnderReview])
            ->where('due_date', '<', $today->toDateString())
            ->where('issued_at', '<', $today->setTimezone((string) config('app.timezone')));
    }
}
