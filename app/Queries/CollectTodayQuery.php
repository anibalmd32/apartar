<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CollectTodayQuery
{
    public const LIMIT = 5;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{totalCents: int, outstandingCents: int, customers: int, late: int, rows: list<array{customerId: int, name: string, phone: string|null, url: string, dueCents: int, daysLate: int, installments: int}>}
     */
    public function get(int $limit = self::LIMIT): array
    {
        $today = CarbonImmutable::now(new DateTimeZone($this->context->organization()->timezone))->startOfDay();

        $installments = (new CollectionFilters(dueTo: $today))->installments()
            ->with(['layaway:id,organization_id,customer_id', 'layaway.customer:id,organization_id,full_name,phone'])
            ->get();

        $rows = $installments
            ->filter(fn (Installment $installment): bool => $installment->layaway instanceof Layaway && $installment->layaway->customer instanceof Customer)
            ->groupBy(fn (Installment $installment): int => (int) $installment->layaway->customer_id)
            ->map(fn (Collection $owed): array => $this->row($owed, $today))
            ->sortBy([['daysLate', 'desc'], ['dueCents', 'desc']])
            ->values();

        return [
            'totalCents' => (int) $rows->sum('dueCents'),
            'outstandingCents' => (int) (new CollectionFilters)->installments()->reorder()->sum(DB::raw('installments.amount_cents - installments.paid_cents')),
            'customers' => $rows->count(),
            'late' => $rows->where('daysLate', '>', 0)->count(),
            'rows' => $rows->take($limit)->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Installment>  $owed
     * @return array{customerId: int, name: string, phone: string|null, url: string, dueCents: int, daysLate: int, installments: int}
     */
    private function row(Collection $owed, CarbonImmutable $today): array
    {
        $customer = $owed->first()?->layaway?->customer;
        assert($customer instanceof Customer);

        $oldest = $owed->min(fn (Installment $installment): string => $installment->due_date->toDateString());

        return [
            'customerId' => (int) $customer->getKey(),
            'name' => $customer->full_name,
            'phone' => $customer->phone,
            'url' => route('customers.show', $customer),
            'dueCents' => (int) $owed->sum(fn (Installment $installment): int => $installment->amount_cents->cents - $installment->paid_cents->cents),
            'daysLate' => max(0, (int) CarbonImmutable::parse((string) $oldest, $today->getTimezone())->diffInDays($today)),
            'installments' => $owed->count(),
        ];
    }
}
