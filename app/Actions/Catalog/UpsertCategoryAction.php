<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CategoryInUseException;
use App\Domain\Catalog\CategoryOrder;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpsertCategoryAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CategoryOrder $order,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CategoryInUseException
     */
    public function handle(string $name, ?string $slug, User $actor, ?Category $category = null): Category
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($name, $slug, $category): Category {
            if (! $category instanceof Category) {
                return $this->create($name, $slug);
            }

            $attributes = ['name' => $name];
            $newSlug = $slug === null ? null : $this->resolveSlug($category, $slug);

            if ($newSlug !== null) {
                $attributes['slug'] = $newSlug;
            }

            $category->forceFill($attributes)->save();

            return $category;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws CategoryInUseException
     */
    public function delete(Category $category, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $products = $this->productsIn($category);

        if ($products > 0) {
            throw CategoryInUseException::cannotBeDeleted($category, $products);
        }

        DB::transaction(function () use ($category): void {
            $category->delete();

            $this->order->apply();
        });
    }

    private function create(string $name, ?string $slug): Category
    {
        $requested = $slug === null ? $name : TaxonomySlug::normalize($slug);

        $category = Category::query()->create([
            'name' => $name,
            'slug' => TaxonomySlug::from($requested, $this->slugIsTaken(), 120),

            'position' => $this->order->next(),
        ]);

        $this->order->apply();

        return $category->refresh();
    }

    /**
     * @throws CategoryInUseException
     */
    private function resolveSlug(Category $category, string $slug): ?string
    {
        $slug = TaxonomySlug::normalize($slug);

        if ($slug === '' || $slug === $category->slug) {
            return null;
        }

        $published = $this->publishedProductsIn($category);

        if ($published > 0) {
            throw CategoryInUseException::slugIsLocked($category, $published);
        }

        return TaxonomySlug::from($slug, $this->slugIsTaken($category), 120);
    }

    private function productsIn(Category $category): int
    {
        return Product::withTrashed()->where('category_id', $category->getKey())->count();
    }

    private function publishedProductsIn(Category $category): int
    {
        return Product::query()
            ->where('category_id', $category->getKey())
            ->where('status', ProductStatus::Published)
            ->count();
    }

    /**
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Category $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Category::query()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }
}
