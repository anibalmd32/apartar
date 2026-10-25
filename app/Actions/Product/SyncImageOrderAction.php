<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\ImageSet;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SyncImageOrderAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<array{id: int, alt?: string|null}>  $images
     *
     * @throws AuthorizationException
     * @throws CrossTenantRelationException
     */
    public function handle(Product $product, array $images, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $product->load('media');

        /** @var array<int, Media> $own */
        $own = $product->getMedia(Product::IMAGES)->keyBy(fn (Media $media): int => (int) $media->getKey())->all();

        DB::transaction(function () use ($images, $own): void {
            $ordered = [];

            foreach ($images as $image) {
                $media = $own[$image['id']] ?? null;

                if (! $media instanceof Media) {
                    throw CrossTenantRelationException::between(Product::class, Media::class);
                }

                if (in_array((int) $media->getKey(), $ordered, true)) {
                    continue;
                }

                $ordered[] = (int) $media->getKey();

                if (array_key_exists('alt', $image)) {
                    $this->writeAlt($media, $image['alt']);
                }
            }

            $this->renumber($own, $ordered);
        });
    }

    private function writeAlt(Media $media, ?string $alt): void
    {
        $alt = trim((string) $alt);

        if ($alt === '') {
            $media->forgetCustomProperty(ImageSet::ALT_PROPERTY);
        } else {
            $media->setCustomProperty(ImageSet::ALT_PROPERTY, $alt);
        }

        $media->save();
    }

    /**
     * @param  array<int, Media>  $own
     * @param  list<int>  $ordered
     */
    private function renumber(array $own, array $ordered): void
    {
        $rest = array_values(array_diff(array_keys($own), $ordered));

        $position = 1;

        foreach ([...$ordered, ...$rest] as $mediaId) {
            $media = $own[$mediaId];

            $media->order_column = $position;
            $media->save();

            $position++;
        }
    }
}
