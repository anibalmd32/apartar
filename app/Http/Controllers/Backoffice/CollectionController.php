<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\User;
use App\Queries\CollectionFilterInput;
use App\Queries\CollectionFilters;
use App\Support\PhoneNumber;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CollectionController extends Controller
{
    use ProjectsMoney;
    use ResolvesTableState;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Layaway::class);

        $state = $this->tableState($request);
        $filters = CollectionFilterInput::resolve($request->query->all(), $state);
        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $listsLayaways = $filters->preset?->listsLayaways() === true;
        $awaiting = $filters->preset?->awaitsDownPayment() === true;

        if ($listsLayaways) {
            $page = $this->paginateTable($filters->layaways()->with([
                'customer:id,organization_id,full_name,phone',
                'customer.conversation:id,organization_id,customer_id',
            ]), $state, simple: true);
            $rows = array_map(fn (Layaway $layaway): array => $awaiting ? $this->downPaymentRow($layaway) : $this->layawayRow($layaway, $timezone), $page->items());
            $meta = $this->tableMeta($page, $state);
        } elseif ($this->grouped($request)) {
            $page = $this->paginateTable($this->customerGroups($filters), $state, simple: true);
            $rows = $this->groupRows($filters, $page->items(), $today);
            $meta = $this->tableMeta($page, $state);
        } else {
            $page = $this->paginateTable($filters->installments()->with([
                'layaway:id,organization_id,code,status,customer_id,installments_count,product_name_snapshot,delivered_at',
                'layaway.customer:id,organization_id,full_name,phone',
                'layaway.customer.conversation:id,organization_id,customer_id',
            ]), $state, simple: true);
            $rows = array_map(fn (Installment $installment): array => $this->installmentRow($installment, $today), $page->items());
            $meta = $this->tableMeta($page, $state);
        }

        return Inertia::render('backoffice/collections/Index', [
            'kind' => $awaiting ? 'down_payments' : ($listsLayaways ? 'layaways' : ($this->grouped($request) ? 'customers' : 'installments')),
            'groupingUrl' => $listsLayaways ? null : route('collections.grouping'),
            'grouped' => ! $listsLayaways && $this->grouped($request),
            'detail' => $request->user() instanceof User && $request->user()->collections_detail === 'installments' ? 'installments' : 'layaways',
            'rows' => $rows,
            'meta' => $meta,
            'definition' => $filters->preset?->definition(app(Settings::class)->longOverdueDays()),
            'summary' => $listsLayaways ? null : $this->summary($filters),
            'awaitingDownPayment' => $listsLayaways ? null : $this->awaitingDownPayment($filters),
            'presetCounts' => fn (): array => $this->presetCounts(),
            'options' => fn (): array => $this->options(),
        ]);
    }

    private function grouped(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->collections_grouped;
    }

    /**
     * @return Builder<Customer>
     */
    private function customerGroups(CollectionFilters $filters): Builder
    {
        $owing = fn (): QueryBuilder => $filters->apply(Installment::query())
            ->join('layaways as owing', 'owing.id', '=', 'installments.layaway_id')
            ->toBase();

        return Customer::query()
            ->with('conversation:id,organization_id,customer_id')
            ->whereIn('customers.id', $owing()->select('owing.customer_id'))
            ->addSelect([
                'customers.*',
                'first_due' => $owing()->whereColumn('owing.customer_id', 'customers.id')->selectRaw('min(installments.due_date)'),
            ])
            ->orderBy('first_due')
            ->orderBy('customers.id');
    }

    /**
     * @param  list<Customer>  $customers
     * @return list<array<string, mixed>>
     */
    private function groupRows(CollectionFilters $filters, array $customers, CarbonImmutable $today): array
    {
        $installments = $filters->installments()
            ->with(['layaway:id,organization_id,code,status,customer_id,installments_count,product_name_snapshot,delivered_at,paid_cents,total_cents'])
            ->whereHas('layaway', fn (Builder $layaway) => $layaway->whereIn('layaways.customer_id', array_map(fn (Customer $customer): int => (int) $customer->getKey(), $customers)))
            ->get()
            ->groupBy(fn (Installment $installment): int => (int) $installment->layaway?->customer_id);

        return array_map(function (Customer $customer) use ($installments, $today): array {
            /** @var list<Installment> $owed */
            $owed = $installments->get((int) $customer->getKey())?->all() ?? [];
            $rows = array_map(fn (Installment $installment): array => $this->installmentRow($installment, $today), $owed);
            $layaways = $this->layawayGroups($owed, $rows);
            $paid = array_sum(array_column($layaways, 'paidCents'));
            $total = array_sum(array_column($layaways, 'totalCents'));

            return [
                'id' => (int) $customer->getKey(),
                'customer' => $this->customerOf($customer),
                'layaway' => ['code' => '', 'url' => route('customers.show', $customer)],
                'installments' => $rows,
                'layawayRows' => $layaways,
                'count' => count($rows),
                'layaways' => count($layaways),
                'pending' => $this->money(new Money(array_sum(array_map(fn (Installment $installment): int => $installment->amount_cents->cents - $installment->paid_cents->cents, $owed)))),
                'paid' => $this->money(new Money($paid)),
                'total' => $this->money(new Money($total)),
                'progress' => $total > 0 ? (int) floor($paid * 100 / $total) : 0,
                'daysLate' => $rows === [] ? 0 : max(array_column($rows, 'daysLate')),
            ];
        }, $customers);
    }

    /**
     * @param  list<Installment>  $owed
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function layawayGroups(array $owed, array $rows): array
    {
        $groups = [];

        foreach ($owed as $index => $installment) {
            /** @var Layaway $layaway */
            $layaway = $installment->layaway;
            $key = (int) $layaway->getKey();
            $row = $rows[$index];
            $groups[$key] ??= [
                'id' => $key,
                'layaway' => $row['layaway'],
                'productName' => $layaway->product_name_snapshot,
                'paidCents' => $layaway->paid_cents->cents,
                'totalCents' => $layaway->total_cents->cents,
                'paid' => $this->money($layaway->paid_cents),
                'total' => $this->money($layaway->total_cents),
                'progress' => $layaway->total_cents->cents > 0 ? (int) floor($layaway->paid_cents->cents * 100 / $layaway->total_cents->cents) : 0,
                'owedCents' => 0,
                'installments' => 0,
                'of' => $layaway->installments_count,
                'nextDue' => $row['dueDate'],
                'daysLate' => 0,
                'delivery' => $row['delivery'],
            ];
            $groups[$key]['owedCents'] += $installment->amount_cents->cents - $installment->paid_cents->cents;
            $groups[$key]['installments']++;
            $groups[$key]['daysLate'] = max($groups[$key]['daysLate'], $row['daysLate']);
        }

        return array_values(array_map(fn (array $group): array => $group + ['owed' => $this->money(new Money($group['owedCents']))], $groups));
    }

    /**
     * @return array{deliveredAt: string|null, url: string|null}
     */
    private function deliveryOf(Layaway $layaway): array
    {
        return [
            'deliveredAt' => $layaway->delivered_at?->toDateString(),
            'url' => $layaway->delivered_at === null && $layaway->status->allowsDelivery() ? route('layaways.deliver', $layaway) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentRow(Installment $installment, CarbonImmutable $today): array
    {
        /** @var Layaway $layaway */
        $layaway = $installment->layaway;
        $due = CarbonImmutable::parse($installment->due_date->toDateString(), $today->getTimezone())->startOfDay();
        $late = (int) $due->diffInDays($today, false);

        return [
            'id' => (int) $installment->getKey(),
            'customer' => $this->customerOf($layaway->customer),
            'layaway' => ['code' => $layaway->code, 'url' => route('layaways.show', $layaway)],
            'number' => $installment->number,
            'of' => $layaway->installments_count,
            'dueDate' => $installment->due_date->toDateString(),
            'amount' => $this->money($installment->amount_cents),
            'paid' => $this->money($installment->paid_cents),
            'daysLate' => max(0, $late),
            'pending' => $this->money(new Money(max(0, $installment->amount_cents->cents - $installment->paid_cents->cents))),
            'productName' => $layaway->product_name_snapshot,
            'delivery' => $this->deliveryOf($layaway),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layawayRow(Layaway $layaway, DateTimeZone $timezone): array
    {
        return [
            'id' => (int) $layaway->getKey(),
            'customer' => $this->customerOf($layaway->customer),
            'layaway' => ['code' => $layaway->code, 'url' => route('layaways.show', $layaway)],
            'productName' => $layaway->product_name_snapshot,
            'completedAt' => $layaway->completed_at?->setTimezone($timezone)->toDateString(),
            'total' => $this->money($layaway->total_cents),
            'delivery' => $this->deliveryOf($layaway),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function downPaymentRow(Layaway $layaway): array
    {
        $total = $layaway->terms()->totalDownPayment();

        return [
            'id' => (int) $layaway->getKey(),
            'customer' => $this->customerOf($layaway->customer),
            'layaway' => ['code' => $layaway->code, 'url' => route('layaways.show', $layaway)],
            'productName' => $layaway->product_name_snapshot,
            'downPayment' => $this->money($total),
            'missing' => $this->money(new Money(max(0, $total->cents - $layaway->paid_cents->cents))),
            'expiresAt' => $layaway->expires_at?->toIso8601String(),
            'delivery' => $this->deliveryOf($layaway),
        ];
    }

    /**
     * @return array{count: int, missing: array<string, mixed>}|null
     */
    private function awaitingDownPayment(CollectionFilters $filters): ?array
    {
        $layaways = (new CollectionFilters(
            CollectionPreset::AwaitingDownPayment,
            search: $filters->search,
            categoryId: $filters->categoryId,
            catalogId: $filters->catalogId,
        ))->layaways()->get();

        if ($layaways->isEmpty()) {
            return null;
        }

        $missing = $layaways->sum(fn (Layaway $layaway): int => max(0, $layaway->terms()->totalDownPayment()->cents - $layaway->paid_cents->cents));

        return ['count' => $layaways->count(), 'missing' => $this->money(new Money((int) $missing))];
    }

    /**
     * @return array{name: string, phone: string|null, phoneLabel: string|null}|null
     */
    private function customerOf(?Customer $customer): ?array
    {
        if (! $customer instanceof Customer) {
            return null;
        }

        $phone = PhoneNumber::tryFromInput($customer->phone);

        $conversation = $customer->relationLoaded('conversation') ? $customer->conversation : null;

        return [
            'name' => $customer->full_name,
            'phone' => $customer->phone,
            'phoneLabel' => $phone instanceof PhoneNumber ? $phone->formatted() : $customer->phone,
            'url' => route('customers.show', $customer),
            'chatUrl' => $conversation instanceof Conversation ? route('chat.show', $conversation) : null,
        ];
    }

    /**
     * @return array{installments: int, pending: array<string, mixed>, customers: int}
     */
    private function summary(CollectionFilters $filters): array
    {
        $base = $filters->apply(Installment::query());

        return [
            'installments' => (clone $base)->count(),
            'pending' => $this->money(new Money((int) (clone $base)->sum(DB::raw('installments.amount_cents - installments.paid_cents')))),
            'customers' => (clone $base)->join('layaways', 'layaways.id', '=', 'installments.layaway_id')->distinct()->count('layaways.customer_id'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function presetCounts(): array
    {
        $counts = [];

        foreach (CollectionPreset::cases() as $preset) {
            $filters = new CollectionFilters($preset);
            $counts[$preset->value] = $preset->listsLayaways() ? $filters->layaways()->count() : $filters->installments()->count();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'presets' => array_map(fn (CollectionPreset $preset): array => ['value' => $preset->value, 'label' => $preset->label()], CollectionPreset::cases()),
            'statuses' => array_map(fn (LayawayStatus $status): array => ['value' => $status->value, 'label' => $status->label()], LayawayStatus::cases()),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn (Category $category): array => ['value' => (string) $category->getKey(), 'label' => $category->name])->all(),
            'catalogs' => Catalog::query()->orderBy('name')->get(['id', 'name'])->map(fn (Catalog $catalog): array => ['value' => (string) $catalog->getKey(), 'label' => $catalog->name])->all(),
        ];
    }
}
