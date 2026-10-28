<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Catalog\FinancingPlan;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\InventorySort;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Money\Money;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\PublishProductRequest;
use App\Http\Requests\Backoffice\StoreProductImagesRequest;
use App\Http\Requests\Backoffice\StoreProductRequest;
use App\Http\Requests\Backoffice\UpdateProductRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use App\Queries\InventoryFilters;
use App\Support\CatalogShareLink;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductController extends Controller
{
    use ProjectsMoney, ResolvesTableState;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeInventory($request);

        $page = null;
        $listing = function () use (&$page, $request): array {
            return $page ??= $this->listing($request);
        };

        return Inertia::render('backoffice/products/Index', [
            'rows' => fn (): array => $listing()['rows'],
            'meta' => fn (): array => $listing()['meta'],
            'categories' => fn (): array => $this->categories(),
            'tags' => fn (): array => $this->tags(),
            'statuses' => fn (): array => $this->statuses(),
            'statusCounts' => fn (): array => $this->statusCounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/products/Form', $this->formProps($request, null));
    }

    public function store(StoreProductRequest $request, UpsertProductAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $product = $upsert->handle($request->toData(), $actor);
        } catch (CrossTenantRelationException) {
            throw $this->asFieldError();
        }

        return to_route('products.edit', $product)->with('status', 'Producto creado.');
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/products/Form', $this->formProps($request, $product));
    }

    public function update(UpdateProductRequest $request, Product $product, UpsertProductAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->handle($request->toData(), $actor, $product);
        } catch (CrossTenantRelationException) {
            throw $this->asFieldError();
        }

        return to_route('products.edit', $product)->with('status', 'Producto guardado.');
    }

    /**
     * @throws ValidationException
     */
    public function publish(
        PublishProductRequest $request,
        Product $product,
        ChangeProductStatusAction $status,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $status->publish($product, $actor, $request->publishedAt());
        } catch (IncompleteProductException $exception) {
            throw ValidationException::withMessages(
                ['status' => $exception->getMessage()] + $exception->missing,
            );
        }

        return to_route('products.index')->with('status', $product->isScheduled()
            ? sprintf('Producto programado: se verá a partir del %s.', $this->scheduledFor($product))
            : 'Producto publicado. Ya se ve en los catálogos publicados donde lo hayas puesto.');
    }

    public function unpublish(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->unpublish($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto despublicado. Vuelve a estar en borrador y deja de verse.');
    }

    public function restore(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->restore($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto restaurado como borrador. Revísalo y publícalo cuando esté listo.');
    }

    public function archive(Request $request, Product $product, ChangeProductStatusAction $status): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $status->archive($product, $actor);

        return to_route('products.index')
            ->with('status', 'Producto archivado. Sigue en tus catálogos, sin mostrarse, por si lo recuperas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Request $request, ?Product $product): array
    {
        return [
            'product' => $product instanceof Product ? $this->productForm($product) : null,
            'defaults' => $this->defaults(),
            'categories' => $this->categories(),
            'tags' => $this->tags(),
            'plan' => $this->plan($request, $product),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function plan(Request $request, ?Product $product): ?array
    {
        $defaults = $this->defaults();

        $plan = FinancingPlan::tryFrom(
            $this->termAmount($request, 'price', $product?->price_cents),
            $this->termAmount($request, 'down_payment', $product?->down_payment_cents),
            $this->termInteger(
                $request,
                'installments_count',
                $product instanceof Product ? $product->installments_count : $defaults['installmentsCount'],
            ),
            $this->termInteger(
                $request,
                'frequency_days',
                $product instanceof Product ? $product->frequency_days : $defaults['frequencyDays'],
            ),
        );

        if (! $plan instanceof FinancingPlan) {
            return null;
        }

        return [
            'downPayment' => $this->money($plan->downPayment),
            'installmentsCount' => $plan->installmentsCount,
            'frequencyDays' => $plan->frequencyDays,
            'installmentAmount' => $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $this->money($plan->lastInstallmentAmount()),
            'hasUnevenLastInstallment' => $plan->hasUnevenLastInstallment(),
            'financedAmount' => $this->money($plan->financedAmount()),
            'total' => $this->money($plan->price),
        ];
    }

    private function termAmount(Request $request, string $key, ?Money $fallback): Money
    {
        $value = $request->query($key);

        if (is_string($value) && trim($value) !== '') {
            try {
                return Money::fromDecimalString(trim($value));
            } catch (InvalidArgumentException) {
                return new Money(0);
            }
        }

        return $fallback ?? new Money(0);
    }

    private function termInteger(Request $request, string $key, int $fallback): int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @return array<string, int>
     */
    private function defaults(): array
    {
        return [
            'installmentsCount' => $this->settingInteger(SettingKey::DefaultInstallmentsCount),
            'frequencyDays' => $this->settingInteger(SettingKey::DefaultFrequencyDays),
            'skuSequence' => Product::withTrashed()->count() + 1,
        ];
    }

    private function settingInteger(SettingKey $key): int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : (int) $key->defaultValue();
    }

    private function publicUrl(Product $product): ?string
    {
        if ($product->status !== ProductStatus::Published || $product->isScheduled()) {
            return null;
        }

        $catalog = $product->catalogs()
            ->whereNotNull('catalogs.published_at')
            ->where(fn ($open) => $open->whereNull('catalogs.expires_at')->orWhere('catalogs.expires_at', '>', now()))
            ->orderBy('catalogs.published_at')
            ->first(['catalogs.id', 'catalogs.slug']);

        return $catalog instanceof Catalog
            ? route(CatalogShareLink::PRODUCT_ROUTE, ['catalog' => $catalog->slug, 'product' => $product->slug])
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function productForm(Product $product): array
    {
        $product->loadMissing('tags');
        $product->loadReservedUnits();

        return $this->specificationRows($product) + $this->imageRows($product) + [
            'publicUrl' => $this->publicUrl($product),
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'description' => ProductDescription::toHtml((string) $product->description),
            'price' => $this->decimals($product->price_cents),
            'compareAtPrice' => $product->compare_at_price_cents === null
                ? null
                : $this->decimals($product->compare_at_price_cents),
            'downPayment' => $this->decimals($product->down_payment_cents),
            'installmentsCount' => $product->installments_count,
            'frequencyDays' => $product->frequency_days,
            'stock' => $product->onHandUnits(),
            'stockBreakdown' => [
                'available' => $product->stock,
                'reserved' => $product->reservedUnits(),
                'shortfall' => $product->shortfallUnits(),
            ],
            'status' => $product->status->value,
            'categoryId' => $product->category_id,
            'tagIds' => $product->tags->map(fn (Tag $tag): int => (int) $tag->getKey())->values()->all(),
            'slugIsLocked' => $product->status === ProductStatus::Published,
            'updatedAt' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{images: list<array<string, mixed>>, maxImages: int}
     */
    private function imageRows(Product $product): array
    {
        $images = $product->getMedia(Product::IMAGES)
            ->map(fn (Media $media): array => [
                'id' => (int) $media->getKey(),
                'set' => ImageSet::of($media)->toArray(),
                'isProcessing' => ! $media->hasGeneratedConversion('zoom'),
                'needsReview' => ImageSet::needsReview($media),
            ])
            ->values()
            ->all();

        return [
            'images' => $images,
            'maxImages' => StoreProductImagesRequest::MAX_IMAGES,
        ];
    }

    /**
     * @return array{specifications: list<string>}
     */
    private function specificationRows(Product $product): array
    {
        $rows = ProductSpecification::query()
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSpecification $specification): string => $specification->text)
            ->values()
            ->all();

        return ['specifications' => $rows];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function listing(Request $request): array
    {
        $state = $this->tableState($request, InventorySort::whitelist(), InventorySort::Created, descendingByDefault: true);

        $query = Product::query()->with(['category', 'editor:id,name', 'media'])->withCount('catalogs')->withReservedUnits();

        InventoryFilters::resolve($request->query->all(), $state)->apply($query);

        /** @var InventorySort $sort */
        $sort = $state->sort;
        $query->orderBy($sort->column(), $state->direction())->orderBy('products.id', $state->direction());

        $page = $this->paginateTable($query, $state);

        /** @var list<Product> $products */
        $products = array_values($page->items());

        return [
            'rows' => array_map(fn (Product $product): array => $this->listRow($product), $products),
            'meta' => $this->tableMeta($page, $state),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->status->value,
            'stock' => $product->stock,
            'reservedUnits' => $product->reservedUnits(),
            'shortfallUnits' => $product->shortfallUnits(),
            'price' => $this->money($product->price_cents),
            'categoryName' => $product->category?->name,
            'catalogsCount' => (int) $product->getAttribute('catalogs_count'),
            'scheduledFor' => $this->scheduledFor($product),
            'thumbUrl' => ($media = $product->getFirstMedia(Product::IMAGES)) instanceof Media ? ImageSet::of($media)->toArray()['thumb'] : null,
            'plan' => [
                'downPayment' => $this->money($product->down_payment_cents),
                'installments' => $product->installments_count,
                'frequencyDays' => $product->frequency_days,
            ],
            'updatedAt' => $product->updated_at
                ?->setTimezone($this->context->organization()->timezone)
                ->isoFormat('lll'),
            'updatedBy' => $product->editor?->name,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return Category::query()
            ->ordered()
            ->get()
            ->map(fn (Category $category): array => [
                'id' => (int) $category->getKey(),
                'name' => $category->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tags(): array
    {
        return Tag::query()
            ->ordered()
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->getKey(),
                'name' => $tag->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Product::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $result = ['all' => 0];

        foreach (ProductStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
            $result['all'] += $result[$status->value];
        }

        $result['scheduled'] = Product::query()
            ->where('status', ProductStatus::Published)
            ->where('published_at', '>', now())
            ->count();

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function statuses(): array
    {
        return array_map(fn (ProductStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], ProductStatus::cases());
    }

    private function scheduledFor(Product $product): ?string
    {
        if (! $product->isScheduled()) {
            return null;
        }

        return $product->published_at
            ?->setTimezone($this->context->organization()->timezone)
            ->isoFormat('LLL');
    }

    private function decimals(Money $amount): string
    {
        return number_format($amount->cents / 100, 2, '.', '');
    }

    private function asFieldError(): ValidationException
    {
        return ValidationException::withMessages([
            'tag_ids' => 'Esa categoría o esa etiqueta no son de tu negocio.',
        ]);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
