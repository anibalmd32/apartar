<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Queries\CollectionFilterInput;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cobranza (`REQ-BOF-042`): la cartera del negocio, una fila por cuota sin
 * cubrir —o por apartado pagado sin entregar—, con los filtros en la query
 * string (RB-7) y la consulta en `CollectionFilters` (RB-1).
 *
 * Un filtro que no se puede usar —un preset que no existe, una fecha que no
 * existe, un rango invertido (SP-2)— **no se aplica y se dice** en la tabla
 * (`REQ-BOF-045` SP-4), en lugar de un `422`: es una página que se abre desde
 * un enlace, y un error de validación no tiene formulario al que volver.
 *
 * Sobrevive a la suspensión en modo lectura (RB-9): está en el grupo
 * `during-suspension`.
 */
final class CollectionController extends Controller
{
    use ProjectsMoney;
    use ResolvesTableState;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Layaway::class);

        $state = $this->tableState($request);
        // `T-070` · la misma lectura que la exportación (`CollectionFilterInput`).
        $filters = CollectionFilterInput::resolve($request->query->all(), $state);
        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $listsLayaways = $filters->preset?->listsLayaways() === true;

        if ($listsLayaways) {
            $page = $this->paginateTable($filters->layaways()->with('customer:id,organization_id,full_name,phone'), $state, simple: true);
            $rows = array_map(fn (Layaway $layaway): array => $this->layawayRow($layaway, $timezone), $page->items());
            $meta = $this->tableMeta($page, $state);
        } else {
            $page = $this->paginateTable($filters->installments()->with([
                'layaway:id,organization_id,code,status,customer_id',
                'layaway.customer:id,organization_id,full_name,phone',
            ]), $state, simple: true);
            $rows = array_map(fn (Installment $installment): array => $this->installmentRow($installment, $today), $page->items());
            $meta = $this->tableMeta($page, $state);
        }

        return Inertia::render('backoffice/collections/Index', [
            'kind' => $listsLayaways ? 'layaways' : 'installments',
            'rows' => $rows,
            'meta' => $meta,
            'definition' => $filters->preset?->definition(),
            'options' => fn (): array => $this->options(),
        ]);
    }

    /**
     * RB-6 · una fila por cuota: el cliente y su teléfono, el apartado, la
     * cuota, el vencimiento, el importe, lo pagado y los días de atraso.
     *
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
            'dueDate' => $installment->due_date->toDateString(),
            'amount' => $this->money($installment->amount_cents),
            'paid' => $this->money($installment->paid_cents),
            'daysLate' => max(0, $late),
        ];
    }

    /**
     * Un apartado pagado sin entregar: quién, qué y desde cuándo espera.
     *
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
     * @return array{name: string, phone: string, phoneLabel: string}|null
     */
    private function customerOf(?Customer $customer): ?array
    {
        if (! $customer instanceof Customer) {
            return null;
        }

        $phone = PhoneNumber::tryFromInput($customer->phone);

        return [
            'name' => $customer->full_name,
            'phone' => $customer->phone,
            'phoneLabel' => $phone instanceof PhoneNumber ? $phone->formatted() : $customer->phone,
        ];
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
            // SP-8 · también los despublicados: miden campañas que ya pasaron.
            'catalogs' => Catalog::query()->orderBy('name')->get(['id', 'name'])->map(fn (Catalog $catalog): array => ['value' => (string) $catalog->getKey(), 'label' => $catalog->name])->all(),
        ];
    }
}
