<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\CatalogFilters;
use App\Domain\Catalog\CatalogSort;
use App\Domain\Catalog\SearchMode;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Support\CatalogCursor;
use App\Support\FullTextTerm;
use Illuminate\Database\Eloquent\Builder;

final class CatalogProductsQuery
{
    /**
     * @return array{items: list<Product>, nextCursor: string|null}
     */
    public function page(Catalog $catalog, CatalogFilters $filters, mixed $cursor): array
    {
        $query = $this->filtered($catalog, $filters);
        $mode = $this->searchMode($query, $filters->term);

        if ($filters->term !== null) {
            $query->search($filters->term, $mode);
        }

        $sort = $filters->effectiveSort($mode);
        $query->with(['category', 'tags', 'media']);

        return $sort === CatalogSort::Relevance && $filters->term !== null
            ? $this->byRelevance($query, $filters->term, $cursor)
            : $this->byKeyset($query, $sort, $cursor);
    }

    /**
     * @return Builder<Product>
     */
    public function visible(Catalog $catalog): Builder
    {
        return Product::query()
            ->published()
            ->inCatalog($catalog)
            ->select(['products.*', ...CatalogSort::SELECTS]);
    }

    /**
     * @return Builder<Product>
     */
    private function filtered(Catalog $catalog, CatalogFilters $filters): Builder
    {
        $query = $this->visible($catalog);

        if ($filters->category !== null) {
            $query->whereIn('products.category_id', Category::query()->select('id')->where('slug', $filters->category));
        }

        if ($filters->tags !== []) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn((new Tag)->qualifyColumn('slug'), $filters->tags));
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function searchMode(Builder $query, ?FullTextTerm $term): SearchMode
    {
        if ($term === null || $term->booleanQuery() === null) {
            return SearchMode::Like;
        }

        return (clone $query)->search($term, SearchMode::FullText)->exists() ? SearchMode::FullText : SearchMode::Like;
    }

    /**
     * @param  Builder<Product>  $query
     * @return array{items: list<Product>, nextCursor: string|null}
     */
    private function byKeyset(Builder $query, CatalogSort $sort, mixed $cursor): array
    {
        foreach ($sort->keyset() ?? [] as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        $page = $query->cursorPaginate(
            CatalogCursor::PER_PAGE,
            ['*'],
            CatalogCursor::NAME,
            CatalogCursor::from($cursor, $sort) ?? CatalogCursor::FIRST_PAGE,
        );

        /** @var list<Product> $items */
        $items = array_values($page->items());

        return ['items' => $items, 'nextCursor' => $page->nextCursor()?->encode()];
    }

    /**
     * @param  Builder<Product>  $query
     * @return array{items: list<Product>, nextCursor: string|null}
     */
    private function byRelevance(Builder $query, FullTextTerm $term, mixed $cursor): array
    {
        $offset = CatalogCursor::offsetOf(CatalogCursor::from($cursor, CatalogSort::Relevance));

        $rows = $query
            ->orderByRaw(sprintf('match (%s) against (? in boolean mode) desc', implode(', ', FullTextTerm::COLUMNS)), [$term->booleanQuery()])
            ->orderBy('products.id')
            ->offset($offset)
            ->limit(CatalogCursor::PER_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > CatalogCursor::PER_PAGE && $offset + CatalogCursor::PER_PAGE <= CatalogCursor::MAX_OFFSET;

        /** @var list<Product> $items */
        $items = $rows->take(CatalogCursor::PER_PAGE)->values()->all();

        return [
            'items' => $items,
            'nextCursor' => $hasMore ? CatalogCursor::encodeOffset($offset + CatalogCursor::PER_PAGE) : null,
        ];
    }
}
