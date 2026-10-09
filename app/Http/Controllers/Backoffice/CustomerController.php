<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Customer;
use App\Queries\CustomerDirectoryFilters;
use App\Queries\CustomerDirectoryQuery;
use App\Queries\CustomerDirectoryRow;
use App\Support\BusinessDay;
use App\Support\TableState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El directorio de clientes (`REQ-BOF-040`): una caja de búsqueda que decide
 * el criterio, los filtros de RB-4 y la prioridad de cobranza como orden.
 *
 * Tabla server-driven (`REQ-BOF-045`) con paginación simple, sin `count(*)`
 * (SP-5). Los dos roles (RB-9); sobrevive a la suspensión en lectura, como la
 * cobranza.
 */
final class CustomerController extends Controller
{
    use ProjectsMoney;
    use ResolvesTableState;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CustomerDirectoryQuery $directory,
    ) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Customer::class);

        $state = $this->tableState($request);
        $page = $this->paginateTable($this->directory->search($this->filters($request, $state)), $state, simple: true);

        return Inertia::render('backoffice/customers/Index', [
            'rows' => array_map(fn (Customer $customer): array => $this->row(CustomerDirectoryRow::of($customer)), $page->items()),
            'meta' => $this->tableMeta($page, $state),
            'options' => fn (): array => [
                'catalogs' => Catalog::query()->orderBy('name')->get(['id', 'name'])->map(fn (Catalog $catalog): array => ['value' => (string) $catalog->getKey(), 'label' => $catalog->name])->all(),
            ],
        ]);
    }

    /**
     * @param  TableState<*>  $state
     */
    private function filters(Request $request, TableState $state): CustomerDirectoryFilters
    {
        $timezone = $this->context->organization()->timezone;
        $flag = fn (mixed $raw): ?bool => $raw === '1' ? true : null;
        $asDate = fn (CarbonImmutable $day): string => $day->toDateString();

        $from = $state->filter('from', $request->query('from'), fn (mixed $raw): ?CarbonImmutable => BusinessDay::parse($raw, $timezone), $asDate);
        $to = $state->filter('to', $request->query('to'), fn (mixed $raw): ?CarbonImmutable => ($day = BusinessDay::parse($raw, $timezone)) !== null && ($from === null || ! $day->lessThan($from)) ? $day : null, $asDate);

        return new CustomerDirectoryFilters(
            search: $state->filter('q', $request->query('q'), fn (mixed $raw): ?string => is_string($raw) && mb_strlen(trim($raw)) <= 100 ? trim($raw) : null),
            overdue: $state->filter('overdue', $request->query('overdue'), $flag) === true,
            dueSoon: $state->filter('due_soon', $request->query('due_soon'), $flag) === true,
            withoutActive: $state->filter('without_active', $request->query('without_active'), $flag) === true,
            nextDueFrom: $from,
            nextDueTo: $to,
            catalogId: $state->filter('catalog', $request->query('catalog'), fn (mixed $raw): ?int => is_int($id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) && Catalog::query()->whereKey($id)->exists() ? $id : null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomerDirectoryRow $row): array
    {
        return [
            'id' => $row->id,
            'url' => route('customers.show', $row->id),
            'name' => $row->name,
            'document' => $row->document,
            'phone' => $row->phone,
            'phoneLabel' => $row->phoneLabel,
            'hasLinkedAccount' => $row->hasLinkedAccount,
            'activeLayaways' => $row->activeLayaways,
            'committed' => $this->money($row->committed),
            'paid' => $this->money($row->paid),
            'remaining' => $this->money($row->remaining),
            'overdueInstallments' => $row->overdueInstallments,
            'inArrears' => $row->inArrears,
            'nextDueDate' => $row->nextDueDate,
        ];
    }
}
