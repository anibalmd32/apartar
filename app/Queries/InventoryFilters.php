<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Support\TableState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los cinco filtros del inventario (`REQ-BOF-020` RB-9) tal como llegan —la
 * query string de la pantalla o los que guardó la exportación (`T-070`)—, y
 * cómo acotan la consulta de productos.
 *
 * Sale de `ProductController` para que la tabla y el archivo **filtren con la
 * misma definición** (lo que pide `REQ-BOF-043` RB-3 para todos los tipos):
 * un valor que no se puede usar —un estado inventado, la categoría o la
 * etiqueta de otra organización o borrada desde entonces— se ignora y se
 * anota en el `TableState` (`REQ-BOF-045` SP-4, `REQ-BOF-043` SP-6).
 */
final readonly class InventoryFilters
{
    /** Las claves que lee. */
    public const KEYS = ['status', 'category', 'tag', 'low_stock', 'q'];

    /** Desde cuántas unidades se considera stock bajo. */
    public const LOW_STOCK = 5;

    /** Hasta cuántos caracteres de búsqueda se usan. */
    public const MAX_SEARCH = 100;

    private function __construct(
        public ?ProductStatus $status,
        public ?int $categoryId,
        public ?int $tagId,
        public bool $lowStock,
        public ?string $search,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  TableState<*>  $state
     */
    public static function resolve(array $input, TableState $state): self
    {
        return new self(
            status: $state->filter('status', $input['status'] ?? null, fn (mixed $raw): ?ProductStatus => is_string($raw) ? ProductStatus::tryFrom($raw) : null),
            categoryId: $state->filter('category', $input['category'] ?? null, fn (mixed $raw): ?int => self::existingId(Category::query(), $raw)),
            tagId: $state->filter('tag', $input['tag'] ?? null, fn (mixed $raw): ?int => self::existingId(Tag::query(), $raw)),
            lowStock: $state->filter('low_stock', $input['low_stock'] ?? null, fn (mixed $raw): ?bool => $raw === '1' ? true : null) === true,
            search: $state->filter('q', $input['q'] ?? null, fn (mixed $raw): ?string => is_string($raw) && trim($raw) !== ''
                ? mb_substr(trim($raw), 0, self::MAX_SEARCH)
                : null),
        );
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->status instanceof ProductStatus) {
            $query->where('products.status', $this->status);
        }

        if ($this->categoryId !== null) {
            $query->where('products.category_id', $this->categoryId);
        }

        if ($this->tagId !== null) {
            $tag = $this->tagId;
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tag));
        }

        if ($this->lowStock) {
            $query->where('products.stock', '<=', self::LOW_STOCK);
        }

        if (is_string($this->search)) {
            // La búsqueda del backoffice es por nombre y SKU (RB-9), con
            // `LIKE` y no con el `FULLTEXT` de la tabla: aquel está pensado
            // para la búsqueda del catálogo público, que ordena por relevancia
            // sobre textos largos (`REQ-CAT-002`). Aquí lo que se busca es un
            // código que la persona ya conoce. Los comodines de `LIKE` que
            // escriba se buscan como texto.
            $like = '%'.addcslashes($this->search, '%_\\').'%';

            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('products.name', 'like', $like)->orWhere('products.sku', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * El identificador si es de una fila de la organización activa, o `null`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function existingId(Builder $query, mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = $query->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
