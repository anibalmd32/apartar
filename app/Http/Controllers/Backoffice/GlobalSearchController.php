<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Tenancy\OrganizationCapability;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Product;
use App\Models\User;
use App\Queries\CustomerSearch;
use App\Support\DocumentNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalSearchController extends Controller
{
    public const PER_GROUP = 5;

    public const MIN_LENGTH = 2;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json(['groups' => []]);
        }

        $groups = array_values(array_filter([
            $user->role->can(OrganizationCapability::Customers) ? ['key' => 'customers', 'label' => 'Clientes', 'items' => $this->customers($term)] : null,
            $user->role->can(OrganizationCapability::Collections) ? ['key' => 'layaways', 'label' => 'Apartados', 'items' => $this->layaways($term)] : null,
            $user->role->can(OrganizationCapability::Products) ? ['key' => 'products', 'label' => 'Productos', 'items' => $this->products($term)] : null,
        ], fn (?array $group): bool => $group !== null && $group['items'] !== []));

        return response()->json(['groups' => $groups]);
    }

    /**
     * @return list<array{title: string, detail: string|null, url: string}>
     */
    private function customers(string $term): array
    {
        $search = CustomerSearch::from($term);

        if (! $search instanceof CustomerSearch) {
            return [];
        }

        return $search->apply(Customer::query())
            ->orderBy('full_name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Customer $customer): array => [
                'title' => $customer->full_name,
                'detail' => DocumentNumber::tryFromInput($customer->document_number)?->formatted() ?? $customer->phone,
                'url' => route('customers.show', $customer),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, detail: string|null, url: string}>
     */
    private function layaways(string $term): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Layaway::query()
            ->with(['customer:id,organization_id,full_name', 'product:id,organization_id,name'])
            ->where(fn ($query) => $query
                ->where('code', 'like', mb_strtoupper($like))
                ->orWhereHas('customer', fn ($customers) => $customers->where('full_name', 'like', $like)))
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Layaway $layaway): array => [
                'title' => $layaway->code,
                'detail' => trim(($layaway->customer->full_name ?? '').' · '.($layaway->product->name ?? ''), ' ·'),
                'url' => route('layaways.show', $layaway),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, detail: string|null, url: string}>
     */
    private function products(string $term): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Product::query()
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get(['id', 'organization_id', 'name', 'sku'])
            ->map(fn (Product $product): array => [
                'title' => $product->name,
                'detail' => $product->sku,
                'url' => route('products.edit', $product),
            ])
            ->values()
            ->all();
    }
}
