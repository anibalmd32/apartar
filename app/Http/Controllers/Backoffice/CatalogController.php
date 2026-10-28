<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Catalog\UnpublishCatalogAction;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\PublishCatalogRequest;
use App\Http\Requests\Backoffice\SyncCatalogProductsRequest;
use App\Http\Requests\Backoffice\UpsertCatalogRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogShareLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CatalogController extends Controller
{
    use ProjectsMoney;

    public const THUMBS = 3;

    public const SUGGESTIONS = 8;

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/catalogs/Index', [
            'catalogs' => $this->listing(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/catalogs/Form', $this->formProps(null, []));
    }

    /**
     * @throws ValidationException
     */
    public function store(UpsertCatalogRequest $request, UpsertCatalogAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $catalog = $this->save($request->toData(), $actor, $upsert, null);

        return to_route('catalogs.edit', $catalog)
            ->with('status', 'Catálogo creado. Añade productos y publícalo cuando esté listo.');
    }

    public function edit(Request $request, Catalog $catalog, PublishCatalogAction $publish): Response
    {
        $this->authorizeInventory($request);

        return Inertia::render('backoffice/catalogs/Form', $this->formProps($catalog, $publish->blockersFor($catalog)));
    }

    /**
     * @throws ValidationException
     */
    public function update(
        UpsertCatalogRequest $request,
        Catalog $catalog,
        UpsertCatalogAction $upsert,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $this->save($request->toData(), $actor, $upsert, $catalog);

        return to_route('catalogs.edit', $catalog)->with('status', 'Catálogo guardado.');
    }

    /**
     * @throws ValidationException
     */
    public function products(
        SyncCatalogProductsRequest $request,
        Catalog $catalog,
        SyncCatalogProductsAction $sync,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $sync->handle($catalog, $request->orderedIds(), $actor);
        } catch (CrossTenantRelationException) {
            throw ValidationException::withMessages([
                'products' => 'Alguno de esos productos ya no está en tu inventario. Recarga la página y vuelve a '
                    .'guardar.',
            ]);
        }

        return back()->with('status', 'Catálogo actualizado.');
    }

    /**
     * @throws ValidationException
     */
    public function publish(
        PublishCatalogRequest $request,
        Catalog $catalog,
        PublishCatalogAction $publish,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $publish->handle($catalog, $request->toData(), $actor);
        } catch (CatalogNotPublishableException $exception) {
            throw ValidationException::withMessages(
                ['status' => $exception->getMessage()] + $exception->missing,
            );
        }

        return to_route('catalogs.edit', $catalog)->with('status', $this->publishedMessage($catalog));
    }

    public function unpublish(
        Request $request,
        Catalog $catalog,
        UnpublishCatalogAction $unpublish,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $unpublish->handle($catalog, $actor);

        return to_route('catalogs.index')->with(
            'status',
            'Catálogo despublicado. Su enlace deja de funcionar y vuelve a estar en borrador; los apartados que '
            .'salieron de él siguen igual.',
        );
    }

    /**
     * @throws ValidationException
     */
    private function save(
        CatalogData $data,
        User $actor,
        UpsertCatalogAction $upsert,
        ?Catalog $catalog,
    ): Catalog {
        try {
            return $upsert->handle($data, $actor, $catalog);
        } catch (SlugLockedException $exception) {
            throw ValidationException::withMessages(['slug' => $exception->getMessage()]);
        }
    }

    private function publishedMessage(Catalog $catalog): string
    {
        return $catalog->visibility() === CatalogVisibility::Listed
            ? 'Catálogo publicado y visible en tu vitrina. Cualquiera con el enlace puede verlo.'
            : 'Catálogo publicado fuera de tu vitrina: cualquiera con el enlace puede verlo, aunque no aparezca en '
                .'tu vitrina.';
    }

    /**
     * @param  array<string, string>  $blockers
     * @return array<string, mixed>
     */
    private function formProps(?Catalog $catalog, array $blockers): array
    {
        return [
            'catalog' => $catalog instanceof Catalog ? $this->catalogForm($catalog) : null,
            'products' => $catalog instanceof Catalog ? $this->productsOf($catalog) : [],
            'availableProducts' => $this->inventory(),
            'suggestedProductIds' => $catalog instanceof Catalog ? $this->suggestionsFor($catalog) : [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogForm(Catalog $catalog): array
    {
        $catalog->loadCount('products')->load('editor:id,name');

        return $this->catalogRow($catalog) + [
            'description' => $catalog->description,
            'coverUrl' => $this->coverUrl($catalog),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(): array
    {
        return Catalog::query()
            ->withCount(['products', 'subscriptions'])
            ->with(['editor:id,name', 'products' => fn ($products) => $products->with('media')->orderBy('catalog_product.position')->limit(self::THUMBS)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Catalog $catalog): array => $this->catalogRow($catalog) + [
                'subscribersCount' => (int) $catalog->getAttribute('subscriptions_count'),
                'coverUrl' => $this->coverUrl($catalog),
                'thumbs' => $catalog->products
                    ->map(fn (Product $product): ?Media => $product->getFirstMedia(Product::IMAGES))
                    ->filter(fn (?Media $media): bool => $media instanceof Media)
                    ->map(fn (Media $media): string => ImageSet::of($media)->thumb)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogRow(Catalog $catalog): array
    {
        $visibility = $catalog->visibility();

        return [
            'id' => (int) $catalog->getKey(),
            'name' => $catalog->name,
            'slug' => $catalog->slug,
            'visibility' => $visibility->value,
            'isListed' => $catalog->is_listed,
            'publishedAt' => $catalog->published_at?->toIso8601String(),
            'expiresAt' => $catalog->expires_at?->toIso8601String(),
            'shareUrl' => CatalogShareLink::of($catalog->slug),
            'slugIsLocked' => $visibility !== CatalogVisibility::Draft,
            'productsCount' => (int) $catalog->getAttribute('products_count'),
            'updatedAt' => $catalog->updated_at
                ?->setTimezone($this->context->organization()->timezone)
                ->isoFormat('lll'),
            'updatedBy' => $catalog->editor?->name,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productsOf(Catalog $catalog): array
    {
        return $catalog->products()
            ->with(['category', 'media'])
            ->orderBy('position')
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventory(): array
    {
        return Product::query()
            ->with(['category', 'media'])
            ->where('status', '!=', ProductStatus::Archived)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product))
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function suggestionsFor(Catalog $catalog): array
    {
        return Product::query()
            ->where('status', '!=', ProductStatus::Archived)
            ->whereDoesntHave('catalogs', fn ($inCatalog) => $inCatalog->whereKey($catalog->getKey()))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::SUGGESTIONS)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productOption(Product $product): array
    {
        return [
            'id' => (int) $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->status->value,
            'price' => $this->money($product->price_cents),
            'categoryName' => $product->category?->name,
            'thumbUrl' => ($media = $product->getFirstMedia(Product::IMAGES)) instanceof Media ? ImageSet::of($media)->thumb : null,
        ];
    }

    private function coverUrl(Catalog $catalog): ?string
    {
        $path = $catalog->cover_path;

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(UpsertCatalogAction::COVER_DISK)->url($path);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
