<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\AttachProductImagesAction;
use App\Actions\Product\SyncImageOrderAction;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreProductImagesRequest;
use App\Http\Requests\Backoffice\SyncImageOrderRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductImageController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttachProductImagesAction $images,
    ) {}

    public function store(StoreProductImagesRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeInventory($request);

        $this->images->handle($product, $request->images());

        return $this->back($product, 'Imágenes subidas. Las versiones optimizadas se generan en segundo plano.');
    }

    /**
     * @throws ValidationException
     */
    public function destroy(
        Request $request,
        Product $product,
        int $media,
        SyncImageOrderAction $order,
    ): RedirectResponse {
        $this->authorizeInventory($request);

        /** @var User $actor */
        $actor = $request->user();

        $images = $product->getMedia(Product::IMAGES);

        $image = $images->first(fn (Media $candidate): bool => (int) $candidate->getKey() === $media);

        if (! $image instanceof Media) {
            abort(404);
        }

        $this->assertNotTheLastImageOfAPublishedProduct($product, $images->count());

        $image->delete();

        $order->handle($product->fresh() ?? $product, [], $actor);

        $this->images->measureStorage();

        return $this->back($product, 'Imagen eliminada.');
    }

    /**
     * @throws ValidationException
     */
    public function order(
        SyncImageOrderRequest $request,
        Product $product,
        SyncImageOrderAction $order,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $order->handle($product, $request->images(), $actor);
        } catch (CrossTenantRelationException) {
            throw ValidationException::withMessages([
                'images' => 'Alguna de esas imágenes no es de este producto.',
            ]);
        }

        return $this->back($product, 'Galería guardada.');
    }

    /**
     * @throws ValidationException
     */
    private function assertNotTheLastImageOfAPublishedProduct(Product $product, int $images): void
    {
        if ($images > 1 || $product->status !== ProductStatus::Published) {
            return;
        }

        throw ValidationException::withMessages([
            'images' => 'Es la única imagen de un producto publicado. Sube otra antes de quitarla, o pásalo a '
                .'borrador si quieres dejarlo sin fotos.',
        ]);
    }

    private function back(Product $product, string $status): RedirectResponse
    {
        return to_route('products.edit', $product)->with('status', $status);
    }

    private function authorizeInventory(Request $request): void
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());
    }
}
