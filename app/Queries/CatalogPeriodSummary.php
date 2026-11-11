<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Safeguard\ParallelEquivalent;
use App\Domain\Safeguard\ParallelEquivalents;
use App\Domain\Safeguard\SafeguardDestination;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogPeriodSummary
{
    public const DAILY_UP_TO_DAYS = 45;

    public const LISTED_PAYMENTS = 100;

    public const PAID_DAY = 'coalesce(payments.paid_on, date(payments.settled_at))';

    /**
     * @var list<array{payment: Payment, equivalent: ParallelEquivalent|null}>|null
     */
    private ?array $rows = null;

    /**
     * @param  Builder<Layaway>  $layaways
     */
    public function __construct(
        private readonly Builder $layaways,
        private readonly Organization $organization,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private readonly ?ParallelEquivalents $equivalents,
    ) {}

    public function granularity(): string
    {
        return $this->from->diffInDays($this->to) <= self::DAILY_UP_TO_DAYS ? 'day' : 'month';
    }

    /**
     * @return array{collected: int, payments: int, layaways: int, fromClosed: int, unallocated: int, parallel: int|null, gap: int|null, withoutRate: int, recorded: int}
     */
    public function totals(): array
    {
        $rows = $this->rows();
        $parallel = null;
        $gap = null;
        $withoutRate = 0;
        $recorded = 0;

        if ($this->equivalents !== null) {
            $parallel = 0;
            $gap = 0;

            foreach ($rows as $row) {
                $recorded += $row['payment']->safeguard === null ? 0 : 1;
                $known = $row['equivalent']?->equivalent;

                if ($known === null) {
                    $withoutRate++;

                    continue;
                }

                $parallel += $known->cents;
                $gap += $known->cents - $row['payment']->amount_cents_usd->cents;
            }
        }

        $closed = [LayawayStatus::Cancelled->value, LayawayStatus::Expired->value];
        $fromClosed = 0;
        $unallocated = 0;

        foreach ($rows as $row) {
            if (in_array($row['payment']->getAttribute('layaway_status'), $closed, true)) {
                $fromClosed += $row['payment']->amount_cents_usd->cents;
            } else {
                $unallocated += $row['payment']->unallocated_cents->cents;
            }
        }

        return [
            'collected' => array_sum(array_map(fn (array $row): int => $row['payment']->amount_cents_usd->cents, $rows)),
            'fromClosed' => $fromClosed,
            'unallocated' => $unallocated,
            'payments' => count($rows),
            'layaways' => (clone $this->layaways)->whereBetween(DB::raw('date(layaways.created_at)'), [$this->from->toDateString(), $this->to->toDateString()])->count(),
            'parallel' => $parallel,
            'gap' => $gap,
            'withoutRate' => $withoutRate,
            'recorded' => $recorded,
        ];
    }

    /**
     * @return list<array{key: string, collected: int, parallel: int, payments: int, layaways: int}>
     */
    public function series(): array
    {
        $daily = $this->granularity() === 'day';
        $keyOf = fn (CarbonImmutable $day): string => $daily ? $day->toDateString() : $day->format('Y-m');
        $buckets = [];

        for ($cursor = $daily ? $this->from : $this->from->startOfMonth(); $cursor->lessThanOrEqualTo($this->to); $cursor = $daily ? $cursor->addDay() : $cursor->addMonth()) {
            $buckets[$keyOf($cursor)] = ['key' => $keyOf($cursor), 'collected' => 0, 'parallel' => 0, 'payments' => 0, 'layaways' => 0];
        }

        foreach ($this->rows() as $row) {
            $key = $keyOf(ParallelEquivalents::dayOf($row['payment'], $this->organization));

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['collected'] += $row['payment']->amount_cents_usd->cents;
            $buckets[$key]['parallel'] += $row['equivalent']?->equivalent->cents ?? 0;
            $buckets[$key]['payments']++;
        }

        $created = (clone $this->layaways)->toBase()
            ->whereBetween(DB::raw('date(layaways.created_at)'), [$this->from->toDateString(), $this->to->toDateString()])
            ->pluck('layaways.created_at');

        foreach ($created as $at) {
            $key = $keyOf(CarbonImmutable::parse((string) $at));

            if (isset($buckets[$key])) {
                $buckets[$key]['layaways']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @return list<array{destination: SafeguardDestination|null, cents: int, payments: int}>
     */
    public function byDestination(): array
    {
        $groups = [];

        foreach ($this->rows() as $row) {
            $known = $row['equivalent']?->equivalent;

            if ($known === null) {
                continue;
            }

            $destination = $row['payment']->safeguard?->destination;
            $key = $destination === null ? '' : $destination->value;
            $groups[$key] ??= ['destination' => $destination, 'cents' => 0, 'payments' => 0];
            $groups[$key]['cents'] += $known->cents;
            $groups[$key]['payments']++;
        }

        usort($groups, fn (array $a, array $b): int => $b['cents'] <=> $a['cents']);

        return $groups;
    }

    /**
     * @return array<int, int>
     */
    public function parallelByProduct(): array
    {
        $byProduct = [];

        foreach ($this->rows() as $row) {
            $productId = (int) $row['payment']->getAttribute('product_id');
            $byProduct[$productId] = ($byProduct[$productId] ?? 0) + ($row['equivalent']?->equivalent->cents ?? 0);
        }

        return $byProduct;
    }

    /**
     * @return list<array{payment: Payment, equivalent: ParallelEquivalent|null}>
     */
    public function latest(): array
    {
        return array_slice(array_reverse($this->rows()), 0, self::LISTED_PAYMENTS);
    }

    public function oldestPaymentDay(): ?CarbonImmutable
    {
        $day = Payment::query()
            ->whereIn('payments.layaway_id', (clone $this->layaways)->select('layaways.id'))
            ->min(DB::raw(self::PAID_DAY));

        return is_string($day) ? CarbonImmutable::parse($day, $this->organization->timezone) : null;
    }

    /**
     * @return list<array{payment: Payment, equivalent: ParallelEquivalent|null}>
     */
    private function rows(): array
    {
        return $this->rows ??= Payment::query()
            ->whereIn('payments.layaway_id', (clone $this->layaways)->select('layaways.id'))
            ->whereBetween(DB::raw(self::PAID_DAY), [$this->from->toDateString(), $this->to->toDateString()])
            ->join('layaways', 'layaways.id', '=', 'payments.layaway_id')
            ->leftJoin('customers', 'customers.id', '=', 'layaways.customer_id')
            ->select('payments.*', 'layaways.code as layaway_code', 'layaways.status as layaway_status', 'layaways.product_id', 'layaways.product_name_snapshot as product_name', 'customers.full_name as customer_name')
            ->with('safeguard')
            ->orderBy(DB::raw(self::PAID_DAY))
            ->orderBy('payments.id')
            ->get()
            ->map(fn (Payment $payment): array => [
                'payment' => $payment,
                'equivalent' => $this->equivalents?->of($payment, $this->organization),
            ])
            ->values()
            ->all();
    }
}
