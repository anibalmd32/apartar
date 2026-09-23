<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las categorías y etiquetas **presentes en un catálogo**, con su conteo
 * (`REQ-CAT-006` RB-3, `REQ-CAT-021` RB-5).
 *
 * El panel de filtros ofrece lo que hay en ese catálogo, no todo el
 * vocabulario del negocio: una ferretería con veinte categorías que arma una
 * campaña de herramientas eléctricas no puede enseñar «Pinturas» con cero
 * resultados detrás. **Un filtro que devuelve cero no se muestra**, y aquí no
 * llega.
 *
 * ## Cuenta lo que se ve, no lo que está dentro
 *
 * El conteo es sobre los productos **publicados** del catálogo
 * (`Product::scopePublished()`), igual que la parrilla. Un `draft` o un
 * archivado conserva su fila en `catalog_product` (`REQ-CAT-007` RB-7) y no
 * se renderiza (`REQ-CAT-021` RB-4); si contara aquí, el panel diría «Taladros
 * (3)» sobre una parrilla con dos. Es la diferencia con
 * `Category::scopeOrdered()`, que cuenta pertenencia para el backoffice.
 *
 * ## Las dos mitades van por el global scope
 *
 * `Category::query()` y `Tag::query()`, y dentro `Product` con su propio
 * filtro: la taxonomía del negocio de al lado no aparece aunque se llame
 * igual (`REQ-CAT-006` RB-1, SP-5), y ninguna línea de aquí escribe un
 * `organization_id`.
 *
 * ## Quién la monta
 *
 * `T-034`, con el panel y los filtros que la usan. Se entrega antes y sin
 * panel a propósito: un panel pintado que no filtra enseñaría una función que
 * no existe, y una consulta probada no enseña nada hasta que alguien la
 * necesita. Lo que **no** decide esta clase es cuándo ocultar un filtro por
 * inútil —todas las cards de la misma categoría (SP-6)—: eso es de quien
 * pinta, que es quien sabe qué hay seleccionado.
 */
final class CatalogFacetsQuery
{
    /**
     * Las facetas del catálogo: categorías en el orden del negocio, etiquetas
     * en orden alfabético.
     *
     * @return array{
     *     categories: list<array{slug: string, name: string, count: int}>,
     *     tags: list<array{slug: string, name: string, count: int}>,
     * }
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
     * El conteo, restringido a lo que ese catálogo enseña.
     *
     * @return Closure(Builder<Product>): Builder<Product>
     */
    private function visibleIn(Catalog $catalog): Closure
    {
        return fn (Builder $products): Builder => $this->shownIn($products, $catalog);
    }

    /**
     * Los productos publicados de ese catálogo, como la parrilla.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    private function shownIn(Builder $products, Catalog $catalog): Builder
    {
        return $products->published()->whereHas(
            'catalogs',
            fn (Builder $catalogs): Builder => $catalogs->whereKey($catalog->getKey()),
        );
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
