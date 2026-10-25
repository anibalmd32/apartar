<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Account\PortalContext;
use App\Domain\Catalog\CatalogExpiredException;
use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\PublicCatalogAccess;
use App\Domain\Subscription\LimitEnforcer;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogViewResource;
use App\Http\Resources\ProductCardResource;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Product;
use App\Queries\CatalogFacetsQuery;
use App\Queries\CatalogProductsQuery;
use App\Support\CatalogCursor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogShowController extends Controller
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly CatalogProductsQuery $products,
        private readonly CatalogFacetsQuery $facets,
    ) {}

    /**
     * @throws ModelNotFoundException<Catalog>
     * @throws CatalogExpiredException
     */
    public function __invoke(Request $request, Catalog $catalog): Response
    {
        PublicCatalogAccess::ensureOpen($catalog);

        $facets = null;
        $filters = null;
        $view = null;

        $readFacets = function () use (&$facets, $catalog): array {
            return $facets ??= $this->facets->forCatalog($catalog);
        };
        $readFilters = function () use (&$filters, $request, $readFacets): CatalogFilters {
            return $filters ??= $this->filters($request, $readFacets);
        };
        $readView = function () use (&$view, $catalog, $request): array {
            return $view ??= $this->catalogView($catalog, $request);
        };

        $response = Inertia::render('catalog/CatalogShow', [
            'catalog' => $readView,
            'products' => fn (): array => $this->grid($catalog, $readFilters(), $request),
            'filters' => fn (): array => $readFilters()->toArray(),
            'facets' => $readFacets,
            'follow' => fn (): array => $this->follow($catalog),
            'stats' => Inertia::defer(fn (): array => [
                'products' => $this->products->visible($catalog)->count(),
                'followers' => CatalogSubscription::query()->where('catalog_id', $catalog->getKey())->count(),
            ]),
            'chat' => fn (): array => [
                'canWrite' => app(PortalContext::class)->customerIn((int) $catalog->organization_id) !== null,
                'sendUrl' => route('catalog.chat', ['catalog' => $catalog->slug]),
                'threadUrl' => route('portal.chat.show', ['organization' => $catalog->organization->slug]),
            ],
        ]);

        return $request->header('X-Inertia') === null
            ? $response->withViewData(['meta' => $this->openGraph($readView(), $catalog->organization->name)])
            : $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogView(Catalog $catalog, Request $request): array
    {
        return CatalogViewResource::forVisitor($catalog, $this->limits->plan(), $this->products)->resolve($request);
    }

    /**
     * @param  callable(): array{categories: list<array{slug: string, name: string, count: int}>, tags: list<array{slug: string, name: string, count: int}>}  $facets
     */
    /**
     * @return array{signedIn: bool, following: bool, url: string}
     */
    private function follow(Catalog $catalog): array
    {
        $account = Auth::guard('account')->user();

        return [
            'signedIn' => $account instanceof Account,
            'following' => $account instanceof Account
                && $account->subscriptions()->where('catalog_id', $catalog->getKey())->exists(),
            'url' => route('catalog.follow', ['catalog' => $catalog->slug]),
        ];
    }

    private function filters(Request $request, callable $facets): CatalogFilters
    {
        $filters = CatalogFilters::fromRequest($request);

        if ($filters->category === null && $filters->tags === []) {
            return $filters;
        }

        $present = $facets();

        return $filters->restrictedTo(
            array_column($present['categories'], 'slug'),
            array_column($present['tags'], 'slug'),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function grid(Catalog $catalog, CatalogFilters $filters, Request $request): array
    {
        $page = $this->products->page($catalog, $filters, $request->query(CatalogCursor::NAME));

        return [
            'data' => array_map(
                fn (Product $product): array => (new ProductCardResource($product, $catalog))->resolve($request),
                $page['items'],
            ),
            'nextCursor' => $page['nextCursor'],
        ];
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array{title: string, description: string, image: string|null, url: string, siteName: string}
     */
    private function openGraph(array $view, string $businessName): array
    {
        /** @var array{card: string}|null $cover */
        $cover = $view['cover'];
        $description = is_string($view['description']) && trim($view['description']) !== ''
            ? $view['description']
            : sprintf('Catálogo de %s. Aparta lo que te guste y págalo en cuotas.', $businessName);

        return [
            'title' => sprintf('%s · %s', (string) $view['name'], $businessName),
            'description' => Str::limit(trim($description), 200),
            'image' => $cover === null ? null : $cover['card'],
            'url' => (string) $view['shareUrl'],
            'siteName' => (string) config('app.name'),
        ];
    }
}
