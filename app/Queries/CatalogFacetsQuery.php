<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class CatalogFacetsQuery
{
    /**
     * @return array{ categories: list<array{slug: string, name: string, count: int}>, tags: list<array{slug: string, name: string, count: int}>, }
     */
    public function forCatalog(Catalog $catalog): array
    {
        $categories = Category::query()
            ->withCount(['products as visible_count' => $this->visibleIn($catalog)])
            ->having('visible_count', '>', 0)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $tags = Tag::query()
            ->withCount(['products as visible_count' => $this->visibleIn($catalog)])
            ->having('visible_count', '>', 0)
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categories->map(fn (Category $category): array => $this->facet($category))->values()->all(),
            'tags' => $tags->map(fn (Tag $tag): array => $this->facet($tag))->values()->all(),
        ];
    }

    /**
     * @return Closure(Builder<Product>): Builder<Product>
     */
    private function visibleIn(Catalog $catalog): Closure
    {
        return fn (Builder $products): Builder => $this->shownIn($products, $catalog);
    }

    /**
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    private function shownIn(Builder $products, Catalog $catalog): Builder
    {
        return $products->published()->inCatalog($catalog);
    }

    /**
     * @return array{slug: string, name: string, count: int}
     */
    private function facet(Category|Tag $term): array
    {
        return [
            'slug' => $term->slug,
            'name' => $term->name,
            'count' => (int) $term->getAttribute('visible_count'),
        ];
    }
}
