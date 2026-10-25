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

final readonly class InventoryFilters
{
    public const KEYS = ['status', 'category', 'tag', 'low_stock', 'q', 'scheduled'];

    public const LOW_STOCK = 5;

    public const MAX_SEARCH = 100;

    private function __construct(
        public ?ProductStatus $status,
        public ?int $categoryId,
        public ?int $tagId,
        public bool $lowStock,
        public ?string $search,
        public bool $scheduled = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param TableState<*> $state
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
            scheduled: $state->filter('scheduled', $input['scheduled'] ?? null, fn (mixed $raw): ?bool => $raw === '1' ? true : null) === true,
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

        if ($this->scheduled) {
            $query->where('products.status', ProductStatus::Published)->where('products.published_at', '>', now());
        }

        if (is_string($this->search)) {
            $like = '%'.addcslashes($this->search, '%_\\').'%';

            $query->where(function (Builder $nested) use ($like): void {
                $nested->where('products.name', 'like', $like)->orWhere('products.sku', 'like', $like);
            });
        }

        return $query;
    }

    /**
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
