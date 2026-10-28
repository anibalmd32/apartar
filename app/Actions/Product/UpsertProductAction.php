<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\TaxonomyByName;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpsertProductAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxonomyByName $taxonomy,
        private readonly SyncSpecificationsAction $specifications,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CrossTenantRelationException
     * @throws ValidationException
     */
    public function handle(ProductData $data, User $actor, ?Product $product = null): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($data, $actor, $product): Product {
            $reserved = 0;

            if (! $product instanceof Product) {
                $this->limits->assertCanCreate(UsageMetric::Products);
            }

            if ($product instanceof Product) {
                $locked = ProductLock::acquire((int) $product->getKey());
                ModelState::adopt($product, $locked->getAttributes());
                $reserved = $product->reservedUnits();
            }

            $before = $product instanceof Product ? $this->auditedTermsOf($product) : [];

            $category = $this->resolveCategory($data);
            $tags = $this->resolveTags($data);

            $this->assertSkuIsFree($data->sku, $product);

            $attributes = [
                'category_id' => $category?->getKey(),
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $this->descriptionFrom($data->description),
                'price_cents' => $data->price,
                'compare_at_price_cents' => $data->compareAtPrice,
                'down_payment_cents' => $data->downPayment,
                'installments_count' => $data->installmentsCount,
                'frequency_days' => $data->frequencyDays,
                'slug' => $this->resolveSlug($data, $product),
            ] + $this->stockFrom($data->stock, $reserved);

            if ($product instanceof Product) {
                $product->editedBy($actor)->forceFill($attributes)->save();
            } else {
                $product = (new Product)->editedBy($actor)->forceFill($attributes + ['status' => ProductStatus::Draft]);
                $product->save();

                $this->limits->adjust(UsageMetric::Products, 1);
            }

            $product->tags()->sync($tags);

            if ($data->specifications !== null) {
                $this->specifications->handle($product, $data->specifications);
            }

            $after = $this->auditedTermsOf($product);

            if ($before !== [] && $before !== $after) {
                $this->audit(
                    AuditEvent::ProductPricingChanged,
                    subject: $product,
                    actor: $actor,
                    before: $before,
                    after: $after,
                );
            }

            return $product;
        });
    }

    /**
     * @return array{stock: int, stock_shortfall: int}
     */
    private function stockFrom(int $onHand, int $reserved): array
    {
        return [
            'stock' => max(0, $onHand - $reserved),
            'stock_shortfall' => max(0, $reserved - $onHand),
        ];
    }

    private function descriptionFrom(?string $description): ?string
    {
        $clean = ProductDescription::sanitize((string) $description);

        return $clean === '' ? null : $clean;
    }

    /**
     * @throws CrossTenantRelationException
     */
    private function resolveCategory(ProductData $data): ?Category
    {
        if ($data->categoryId !== null) {
            $category = Category::query()->find($data->categoryId);

            if (! $category instanceof Category) {
                throw CrossTenantRelationException::between(Product::class, Category::class);
            }

            return $category;
        }

        if ($data->categoryName !== null && trim($data->categoryName) !== '') {
            return $this->taxonomy->category($data->categoryName);
        }

        return null;
    }

    /**
     * @return list<int>
     *
     * @throws CrossTenantRelationException
     */
    private function resolveTags(ProductData $data): array
    {
        $ids = [];

        foreach (array_unique($data->tagIds) as $tagId) {
            $tag = Tag::query()->find($tagId);

            if (! $tag instanceof Tag) {
                throw CrossTenantRelationException::between(Product::class, Tag::class);
            }

            $ids[] = (int) $tag->getKey();
        }

        foreach ($data->tagNames as $name) {
            if (trim($name) === '') {
                continue;
            }

            $ids[] = (int) $this->taxonomy->tag($name)->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @throws ValidationException
     */
    private function resolveSlug(ProductData $data, ?Product $product): string
    {
        if (! $product instanceof Product) {
            return TaxonomySlug::from($data->slug ?? $data->name, $this->slugIsTaken(), 200);
        }

        $requested = $data->slug === null ? null : TaxonomySlug::normalize($data->slug, 200);

        if ($requested === null || $requested === '' || $requested === $product->slug) {
            return $product->slug;
        }

        if ($product->status === ProductStatus::Published) {
            throw ValidationException::withMessages([
                'slug' => 'El enlace de un producto publicado ya no se puede cambiar: su URL ya circuló y '
                    .'cambiarla rompería los enlaces que compartiste. El nombre sí lo puedes cambiar.',
            ]);
        }

        return TaxonomySlug::from($requested, $this->slugIsTaken($product), 200);
    }

    /**
     * @throws ValidationException
     */
    private function assertSkuIsFree(string $sku, ?Product $product): void
    {
        $query = Product::withTrashed()->where('sku', $sku);

        if ($product instanceof Product) {
            $query->whereKeyNot($product->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'sku' => sprintf('Ya tienes un producto con el SKU «%s».', $sku),
            ]);
        }
    }

    /**
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Product $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Product::withTrashed()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedTermsOf(Product $product): array
    {
        return [
            'price_cents' => $product->price_cents->cents,
            'down_payment_cents' => $product->down_payment_cents->cents,
            'installments_count' => $product->installments_count,
            'frequency_days' => $product->frequency_days,
        ];
    }
}
