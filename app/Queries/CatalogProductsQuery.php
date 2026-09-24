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

/**
 * Un lote de la parrilla pública con sus filtros aplicados (`REQ-CAT-002`,
 * `REQ-CAT-006`, `REQ-CAT-021` RB-4 a RB-6).
 *
 * ## Una sola base, la misma que cuenta el panel
 *
 * `visible()` es `Product::query()->published()->inCatalog($catalog)`, y
 * `CatalogFacetsQuery` cuenta con esos mismos dos *scopes*. No es una
 * coincidencia que haya que mantener: es lo que hace que «Taladros (4)»
 * devuelva cuatro al pulsarlo. Un `draft` o un archivado queda fuera de los
 * dos sitios por el mismo camino, y un producto de otro catálogo —de la misma
 * organización o de otra— no tiene por dónde entrar en ninguno.
 *
 * Encima, en este orden: la categoría (única, `=`), las etiquetas (**`OR`**:
 * basta con una, RB-4), el término y el orden. Los filtros llegan ya
 * restringidos a lo que el panel ofrece (`CatalogFilters::restrictedTo()`).
 *
 * ## El modo de búsqueda se decide con una pregunta
 *
 * RB-2 degrada a `LIKE` también cuando el índice «no devuelve nada», y eso no
 * se sabe sin preguntarlo: un `exists()` con el `MATCH` sobre la consulta ya
 * filtrada. Es una consulta más, y a cambio el modo depende **solo de los
 * datos y de los filtros**, no del lote: el segundo «Cargar más» busca igual
 * que el primero, y su cursor tiene la forma que el primero emitió.
 *
 * ## Cuándo se pagina por cursor y cuándo por desplazamiento
 *
 * Ver `CatalogSort`: por cursor (`cursorPaginate`, sin `count(*)`, SP-7) todo
 * orden por columnas; por desplazamiento la relevancia, pidiendo uno de más
 * para saber si hay otro lote sin contar.
 */
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
     * Los productos visibles de ese catálogo, sin filtros: la base de la
     * parrilla, de las facetas y de la portada de respaldo.
     *
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
            // Por el modelo y no por un `join`: `Category::query()` lleva el
            // *global scope*, así que el slug se resuelve en este tenant.
            $query->whereIn('products.category_id', Category::query()->select('id')->where('slug', $filters->category));
        }

        if ($filters->tags !== []) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn((new Tag)->qualifyColumn('slug'), $filters->tags));
        }

        return $query;
    }

    /**
     * `FullText` si el índice puede contestar y encuentra algo con estos
     * filtros; `Like` en cualquier otro caso (RB-2).
     *
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
     * Por puntuación de `MATCH`, desempatada por `id` para que el orden sea
     * total y el desplazamiento no repita ni salte filas (RB-4).
     *
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
