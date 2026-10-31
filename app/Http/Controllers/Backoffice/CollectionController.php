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
use App\Queries\CollectionFilterInput;
use App\Queries\CollectionFilters;
use App\Support\PhoneNumber;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
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
        } else {
            $page = $this->paginateTable($filters->installments()->with([
                'layaway:id,organization_id,code,status,customer_id,installments_count',
                'layaway.customer:id,organization_id,full_name,phone',
                'layaway.customer.conversation:id,organization_id,customer_id',
            ]), $state, simple: true);
            $rows = array_map(fn (Installment $installment): array => $this->installmentRow($installment, $today), $page->items());
            $meta = $this->tableMeta($page, $state);
        }

        return Inertia::render('backoffice/collections/Index', [
            'kind' => $awaiting ? 'down_payments' : ($listsLayaways ? 'layaways' : 'installments'),
            'rows' => $rows,
            'meta' => $meta,
            'definition' => $filters->preset?->definition(app(Settings::class)->longOverdueDays()),
            'summary' => $listsLayaways ? null : $this->summary($filters),
            'awaitingDownPayment' => $listsLayaways ? null : $this->awaitingDownPayment(),
            'presetCounts' => fn (): array => $this->presetCounts(),
            'options' => fn (): array => $this->options(),
        ]);
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
        ];
    }

    /**
     * @return array{count: int, missing: array<string, mixed>}|null
     */
    private function awaitingDownPayment(): ?array
    {
        $layaways = (new CollectionFilters(CollectionPreset::AwaitingDownPayment))->layaways()->get();

        if ($layaways->isEmpty()) {
            return null;
        }

        $missing = $layaways->sum(fn (Layaway $layaway): int => max(0, $layaway->terms()->totalDownPayment()->cents - $layaway->paid_cents->cents));

        return ['count' => $layaways->count(), 'missing' => $this->money(new Money((int) $missing))];
    }

    /**
     * @return array{name: string, phone: string, phoneLabel: string}|null
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
