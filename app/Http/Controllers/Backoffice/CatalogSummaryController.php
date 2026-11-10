<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Safeguard\ParallelEquivalents;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Queries\CatalogPeriodSummary;
use App\Queries\CatalogSalesSummary;
use App\Support\HumanDate;
use App\Support\SummaryPeriod;
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

        $organization = $this->context->organization();
        $today = CarbonImmutable::now($organization->timezone)->startOfDay();
        $summary = new CatalogSalesSummary($catalog, $today);
        $totals = $summary->totals();
        $cents = fn (int $value): array => $this->money(new Money($value));
        $earliest = (new CatalogPeriodSummary($summary->layaways(), $organization, $today, $today, null))->oldestPaymentDay();
        $period = SummaryPeriod::fromRequest($request, $today, $earliest, $catalog->safeguard_enabled ? 'todo' : SummaryPeriod::DEFAULT_PRESET);
        $figures = new CatalogPeriodSummary(
            $summary->layaways(),
            $organization,
            $period->from,
            $period->to,
            $catalog->safeguard_enabled ? app(ParallelEquivalents::class) : null,
        );
        $periodTotals = $figures->totals();
        $parallelByProduct = $catalog->safeguard_enabled ? $figures->parallelByProduct() : [];
        $latest = $figures->latest();

        return Inertia::render('backoffice/catalogs/Summary', [
            'catalog' => [
                'id' => (int) $catalog->getKey(),
                'name' => $catalog->name,
                'editUrl' => route('catalogs.edit', $catalog),
                'url' => route('catalogs.summary', $catalog),
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
                'parallel' => $catalog->safeguard_enabled ? $cents($parallelByProduct[(int) $row['product']->getKey()] ?? 0) : null,
            ], $summary->products()),
            'debtors' => array_map(fn (array $debtor): array => [
                'name' => $debtor['name'],
                'url' => route('customers.show', $debtor['customer_id']),
                'layaways' => $debtor['layaways'],
                'remaining' => $cents($debtor['remaining']),
            ], $summary->topDebtors()),
            'period' => $period->toArray($figures->granularity()),
            'periodTotals' => [
                'collected' => $cents($periodTotals['collected']),
                'payments' => $periodTotals['payments'],
                'average' => $periodTotals['payments'] > 0 ? $cents(intdiv($periodTotals['collected'], $periodTotals['payments'])) : null,
                'layaways' => $periodTotals['layaways'],
            ],
            'series' => array_map(fn (array $point): array => [
                'key' => $point['key'],
                'label' => SummaryPeriod::labelOf($point['key']),
                'collected' => $cents($point['collected']),
                'payments' => $point['payments'],
                'layaways' => $point['layaways'],
                'parallel' => $catalog->safeguard_enabled ? $cents($point['parallel']) : null,
            ], $figures->series()),
            'safeguard' => $catalog->safeguard_enabled ? [
                'parallel' => $cents((int) $periodTotals['parallel']),
                'gap' => $cents((int) $periodTotals['gap']),
                'payments' => $periodTotals['payments'],
                'recorded' => $periodTotals['recorded'],
                'withoutRate' => $periodTotals['withoutRate'],
                'byDestination' => array_map(fn (array $row): array => [
                    'label' => $row['destination']?->label() ?? 'Sin registrar',
                    'amount' => $cents($row['cents']),
                    'payments' => $row['payments'],
                    'recorded' => $row['destination'] !== null,
                ], $figures->byDestination()),
            ] : null,
            'payments' => array_map(function (array $row) use ($organization): array {
                $payment = $row['payment'];

                return [
                    'id' => (int) $payment->getKey(),
                    'date' => HumanDate::short(ParallelEquivalents::dayOf($payment, $organization)),
                    'customer' => $payment->getAttribute('customer_name'),
                    'product' => (string) $payment->getAttribute('product_name'),
                    'layawayCode' => (string) $payment->getAttribute('layaway_code'),
                    'layawayUrl' => route('layaways.show', ['layaway' => (string) $payment->getAttribute('layaway_code')]),
                    'amount' => $this->money($payment->amount_cents_usd),
                    'declared' => MoneyFormatter::format(Money::fromDecimalString($payment->declared_amount, $payment->declared_currency)),
                    'parallel' => $row['equivalent']?->equivalent === null ? null : MoneyFormatter::format($row['equivalent']->equivalent),
                    'safeguard' => $payment->safeguard?->destination->label(),
                ];
            }, $latest),
            'paymentsTruncated' => $periodTotals['payments'] > count($latest),
        ]);
    }
}
