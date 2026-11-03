<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Queries\CatalogSalesSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogSummaryController extends Controller
{
    use ProjectsMoney;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, Catalog $catalog): Response
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());

        $summary = new CatalogSalesSummary($catalog, CarbonImmutable::now($this->context->organization()->timezone)->startOfDay());
        $totals = $summary->totals();
        $cents = fn (int $value): array => $this->money(new Money($value));

        return Inertia::render('backoffice/catalogs/Summary', [
            'catalog' => [
                'id' => (int) $catalog->getKey(),
                'name' => $catalog->name,
                'editUrl' => route('catalogs.edit', $catalog),
            ],
            'totals' => [
                'layaways' => $totals['layaways'],
                'units' => $totals['units'],
                'committed' => $cents($totals['committed']),
                'paid' => $cents($totals['paid']),
                'remaining' => $cents($totals['remaining']),
                'progress' => $totals['committed'] > 0 ? (int) floor($totals['paid'] * 100 / $totals['committed']) : 0,
                'overdue' => $cents($totals['overdue']),
                'overdueLayaways' => $totals['overdue_layaways'],
                'upcoming' => $cents($totals['upcoming']),
                'delivered' => $totals['delivered'],
                'awaitingDelivery' => $totals['awaiting_delivery'],
            ],
            'statuses' => $summary->byStatus(),
            'products' => array_map(fn (array $row): array => [
                'id' => (int) $row['product']->getKey(),
                'name' => $row['product']->name,
                'sku' => $row['product']->sku,
                'url' => route('products.edit', $row['product']),
                'price' => $cents($row['product']->price_cents->cents),
                'stock' => $row['product']->stock,
                'reserved' => $row['reserved'],
                'sold' => $row['sold'],
                'committed' => $cents($row['committed']),
                'paid' => $cents($row['paid']),
                'remaining' => $cents($row['remaining']),
                'progress' => $row['committed'] > 0 ? (int) floor($row['paid'] * 100 / $row['committed']) : 0,
            ], $summary->products()),
            'collected' => array_map(fn (array $month): array => [
                'month' => $month['month'],
                'amount' => $cents($month['cents']),
            ], $summary->collectedByMonth()),
            'debtors' => array_map(fn (array $debtor): array => [
                'name' => $debtor['name'],
                'url' => route('customers.show', $debtor['customer_id']),
                'layaways' => $debtor['layaways'],
                'remaining' => $cents($debtor['remaining']),
            ], $summary->topDebtors()),
        ]);
    }
}
