<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\User;
use App\Support\UniqueCatalogSlug;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class UpsertCatalogAction
{
    private const COVER_DIRECTORY = 'catalogos';

    public const COVER_DISK = 'public';

    public function __construct(
        private readonly TenantContext $context,
        private readonly UniqueCatalogSlug $slugs,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SlugLockedException
     */
    public function handle(CatalogData $data, User $actor, ?Catalog $catalog = null): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        if (! $catalog instanceof Catalog) {
            return $this->create($data, $actor);
        }

        return $this->update($catalog->editedBy($actor), $data);
    }

    private function create(CatalogData $data, User $actor): Catalog
    {
        return $this->slugs->generate($data->slug ?? $data->name, function (string $slug) use ($data, $actor): Catalog {
            $catalog = (new Catalog)->editedBy($actor)->forceFill([
                'name' => $data->name,
                'slug' => $slug,
                'description' => $data->description,
                'cover_path' => $data->cover instanceof UploadedFile ? $this->storeCover($data->cover) : null,
                'is_listed' => true,
                'published_at' => null,
                'expires_at' => null,
            ]);

            $catalog->save();

            return $catalog;
        });
    }

    private function update(Catalog $catalog, CatalogData $data): Catalog
    {
        $attributes = [
            'name' => $data->name,
            'description' => $data->description,
        ];

        if ($data->cover instanceof UploadedFile) {
            $attributes['cover_path'] = $this->storeCover($data->cover, $catalog->cover_path);
        }

        $requested = $this->requestedSlug($catalog, $data->slug);

        if ($requested === null) {
            $catalog->forceFill($attributes)->save();

            return $catalog;
        }

        return $this->slugs->generate($requested, function (string $slug) use ($catalog, $attributes): Catalog {
            $catalog->forceFill($attributes + ['slug' => $slug])->save();

            return $catalog;
        });
    }

    /**
     * @throws SlugLockedException
     */
    private function requestedSlug(Catalog $catalog, ?string $slug): ?string
    {
        if ($slug === null || $slug === '' || $slug === $catalog->slug) {
            return null;
        }

        if ($catalog->visibility() !== CatalogVisibility::Draft) {
            throw SlugLockedException::catalogIsPublished($catalog->slug);
        }

        return $slug;
    }

    private function storeCover(UploadedFile $cover, ?string $previous = null): string
    {
        $organizationId = $this->context->organizationId();

        $path = (string) $cover->store(self::COVER_DIRECTORY.'/'.$organizationId, self::COVER_DISK);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk(self::COVER_DISK)->delete($previous);
        }

        return $path;
    }
}
