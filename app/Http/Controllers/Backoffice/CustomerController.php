<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Customer;
use App\Queries\CustomerDirectoryFilterInput;
use App\Queries\CustomerDirectoryFilters;
use App\Queries\CustomerDirectoryQuery;
use App\Queries\CustomerDirectoryRow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController extends Controller
{
    use ProjectsMoney;
    use ResolvesTableState;

    public function __construct(private readonly CustomerDirectoryQuery $directory) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Customer::class);

        $state = $this->tableState($request);
        $page = $this->paginateTable($this->directory->search(CustomerDirectoryFilterInput::resolve($request->query->all(), $state)), $state, simple: true);

        return Inertia::render('backoffice/customers/Index', [
            'rows' => array_map(fn (Customer $customer): array => $this->row(CustomerDirectoryRow::of($customer)), $page->items()),
            'meta' => $this->tableMeta($page, $state),
            'options' => fn (): array => [
                'catalogs' => Catalog::query()->orderBy('name')->get(['id', 'name'])->map(fn (Catalog $catalog): array => ['value' => (string) $catalog->getKey(), 'label' => $catalog->name])->all(),
            ],
            'filterCounts' => fn (): array => [
                'all' => $this->directory->search(new CustomerDirectoryFilters)->count(),
                'overdue' => $this->directory->search(new CustomerDirectoryFilters(overdue: true))->count(),
                'due_soon' => $this->directory->search(new CustomerDirectoryFilters(dueSoon: true))->count(),
                'without_active' => $this->directory->search(new CustomerDirectoryFilters(withoutActive: true))->count(),
            ],
        ]);
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
