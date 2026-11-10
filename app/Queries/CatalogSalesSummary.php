<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Catalog;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CatalogSalesSummary
{
    public const UPCOMING_DAYS = 7;

    public const TOP_DEBTORS = 5;

    /** @var list<int> */
    private array $productIds;

    public function __construct(private Catalog $catalog, private CarbonImmutable $today)
    {
        $this->productIds = array_map('intval', $catalog->products()->pluck('products.id')->all());
    }

    /**
     * @return Builder<Layaway>
     */
    public function layaways(): Builder
    {
        return Layaway::query()->where(fn (Builder $query) => $query
            ->where('layaways.catalog_id', $this->catalog->getKey())
            ->orWhere(fn (Builder $query) => $query->whereNull('layaways.catalog_id')->whereIn('layaways.product_id', $this->productIds)));
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        $live = array_map(fn (LayawayStatus $status): string => $status->value, [LayawayStatus::PendingDownPayment, LayawayStatus::Active, LayawayStatus::Overdue, LayawayStatus::Completed]);
        $row = (array) $this->layaways()->toBase()
            ->selectRaw('count(*) as layaways')
            ->selectRaw('coalesce(sum(case when status in (?, ?, ?, ?) then total_cents else 0 end), 0) as committed', $live)
            ->selectRaw('coalesce(sum(case when status in (?, ?, ?, ?) then paid_cents else 0 end), 0) as paid', $live)
            ->selectRaw('coalesce(sum(case when status in (?, ?, ?, ?) then remaining_cents else 0 end), 0) as remaining', $live)
            ->selectRaw('coalesce(sum(case when status in (?, ?, ?, ?) then quantity else 0 end), 0) as units', $live)
            ->selectRaw('coalesce(sum(case when status in (?, ?, ?, ?) and delivered_at is not null then 1 else 0 end), 0) as delivered', $live)
            ->selectRaw("coalesce(sum(case when status = 'completed' and delivered_at is null then 1 else 0 end), 0) as awaiting_delivery")
            ->first();

        $owed = $this->owedInstallments();

        return array_map('intval', $row) + [
            'overdue' => (int) (clone $owed)->where('installments.due_date', '<', $this->today->toDateString())->sum(DB::raw('installments.amount_cents - installments.paid_cents')),
            'overdue_layaways' => (int) (clone $owed)->where('installments.due_date', '<', $this->today->toDateString())->distinct()->count('installments.layaway_id'),
            'upcoming' => (int) (clone $owed)->whereBetween('installments.due_date', [$this->today->toDateString(), $this->today->addDays(self::UPCOMING_DAYS)->toDateString()])->sum(DB::raw('installments.amount_cents - installments.paid_cents')),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function byStatus(): array
    {
        $counts = $this->layaways()->toBase()->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->all();

        return array_combine(
            array_map(fn (LayawayStatus $status): string => $status->value, LayawayStatus::cases()),
            array_map(fn (LayawayStatus $status): int => (int) ($counts[$status->value] ?? 0), LayawayStatus::cases()),
        );
    }

    /**
     * @return list<array{product: Product, reserved: int, sold: int, committed: int, paid: int, remaining: int}>
     */
    public function products(): array
    {
        $rows = $this->layaways()->toBase()
            ->whereNotIn('status', [LayawayStatus::Cancelled->value, LayawayStatus::Expired->value])
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw("coalesce(sum(case when status <> 'completed' then quantity else 0 end), 0) as reserved")
            ->selectRaw("coalesce(sum(case when status = 'completed' then quantity else 0 end), 0) as sold")
            ->selectRaw('coalesce(sum(total_cents), 0) as committed, coalesce(sum(paid_cents), 0) as paid, coalesce(sum(remaining_cents), 0) as remaining')
            ->get()
            ->keyBy('product_id');

        return $this->catalog->products()->withTrashed()->orderBy('catalog_product.position')->get()
            ->map(function (Product $product) use ($rows): array {
                $row = (array) ($rows->get($product->getKey()) ?? []);

                return [
                    'product' => $product,
                    'reserved' => (int) ($row['reserved'] ?? 0),
                    'sold' => (int) ($row['sold'] ?? 0),
                    'committed' => (int) ($row['committed'] ?? 0),
                    'paid' => (int) ($row['paid'] ?? 0),
                    'remaining' => (int) ($row['remaining'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{customer_id: int, name: string, layaways: int, remaining: int}>
     */
    public function topDebtors(): array
    {
        return $this->layaways()->toBase()
            ->join('customers', 'customers.id', '=', 'layaways.customer_id')
            ->whereIn('layaways.status', [LayawayStatus::Active->value, LayawayStatus::Overdue->value, LayawayStatus::PendingDownPayment->value])
            ->groupBy('layaways.customer_id', 'customers.full_name')
            ->selectRaw('layaways.customer_id, customers.full_name as name, count(*) as layaways, sum(layaways.remaining_cents) as remaining')
            ->orderByDesc('remaining')
            ->limit(self::TOP_DEBTORS)
            ->get()
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'name' => (string) $row->name,
                'layaways' => (int) $row->layaways,
                'remaining' => (int) $row->remaining,
            ])
            ->all();
    }

    /**
     * @return Builder<Installment>
     */
    private function owedInstallments(): Builder
    {
        return Installment::query()
            ->whereColumn('installments.paid_cents', '<', 'installments.amount_cents')
            ->whereIn('installments.layaway_id', $this->layaways()
                ->whereIn('layaways.status', [LayawayStatus::Active->value, LayawayStatus::Overdue->value])
                ->select('layaways.id'));
    }
}
