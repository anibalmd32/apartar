<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\SyncImageOrderAction;
use App\Domain\Catalog\ImageSet;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreProductImagesRequest;
use App\Http\Requests\Backoffice\SyncImageOrderRequest;
use App\Models\Product;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class ProductImageController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageCalculator $usage,
        private readonly LimitEnforcer $limits,
    ) {}

    public function store(StoreProductImagesRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeInventory($request);

        try {
            foreach ($request->images() as $file) {
                DB::transaction(function () use ($product, $file): void {
                    $this->usage->recountLocked(UsageMetric::StorageMb);
                    $this->limits->assertCanCreate(UsageMetric::StorageMb, $this->usage->megabytesAdded((int) $file->getSize()));
                    $this->attach($product, $file);
                    $this->usage->recountLocked(UsageMetric::StorageMb);
                });
            }
        } finally {
            $this->measureStorage();
        }

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

        $this->measureStorage();

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

    private function measureStorage(): void
    {
        try {
            $this->usage->refresh($this->context->organization(), UsageMetric::StorageMb);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function attach(Product $product, UploadedFile $file): void
    {
        $position = $product->getMedia(Product::IMAGES)->count() + 1;

        $product
            ->addMedia($file)
            ->usingName(ImageSet::defaultAlt($product->name, $position))
            ->withCustomProperties([
                TenantPathGenerator::ORGANIZATION_PROPERTY => $this->context->organizationId(),
            ])
            ->toMediaCollection(Product::IMAGES);
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
