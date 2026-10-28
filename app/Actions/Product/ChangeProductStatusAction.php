<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use App\Rules\CoherentFinancingTerms;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeProductStatusAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws IncompleteProductException
     * @throws ValidationException
     */
    public function publish(Product $product, User $actor, ?DateTimeInterface $at = null): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $this->assertStepIsAllowed($product, ProductStatus::Published);

        if ($product->status === ProductStatus::Published) {
            return $product;
        }

        $this->assertIsComplete($product);

        return $this->transitionTo($product, ProductStatus::Published, $actor, [
            'published_at' => $at ?? now(),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function unpublish(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->backToDraft($product, $actor);
    }

    /**
     * @throws AuthorizationException
     */
    public function restore(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->backToDraft($product, $actor);
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->transitionTo($product, ProductStatus::Archived, $actor);
    }

    private function backToDraft(Product $product, User $actor): Product
    {
        return $this->transitionTo($product, ProductStatus::Draft, $actor, ['published_at' => null]);
    }

    /**
     * @throws ValidationException
     */
    private function assertStepIsAllowed(Product $product, ProductStatus $target): void
    {
        if ($product->status === $target || in_array($target, self::reachableFrom($product->status), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Un producto archivado no se publica de golpe: restáuralo primero —vuelve a borrador— y '
                .'revisa precio, existencias y fotos antes de que vuelva a verse. Sigue en los catálogos donde '
                .'estaba, así que se publicaría en todos ellos a la vez.',
        ]);
    }

    /**
     * @return list<ProductStatus>
     */
    private static function reachableFrom(ProductStatus $status): array
    {
        return match ($status) {
            ProductStatus::Draft => [ProductStatus::Published, ProductStatus::Archived],
            ProductStatus::Published => [ProductStatus::Draft, ProductStatus::Archived],
            ProductStatus::Archived => [ProductStatus::Draft],
        };
    }

    /**
     * @throws IncompleteProductException
     */
    private function assertIsComplete(Product $product): void
    {
        $missing = $this->missingToPublish($product);

        if ($missing !== []) {
            throw IncompleteProductException::of($product, $missing);
        }
    }

    /**
     * @return array<string, string>
     */
    private function missingToPublish(Product $product): array
    {
        $missing = [];

        if (! $product->hasImages()) {
            $missing['images'] = 'Sube al menos una foto: una ficha sin imagen no vende, y el catálogo la '
                .'pintaría como un hueco.';
        }

        if ($product->category_id === null) {
            $missing['category_id'] = 'Elige una categoría: es por donde tus clientes filtran dentro del catálogo.';
        }

        if (ProductDescription::plainText((string) $product->description) === '') {
            $missing['description'] = 'Escribe la descripción: es lo que lee quien está decidiendo si lo aparta.';
        }

        $violations = CoherentFinancingTerms::violationsIn(
            $product->price_cents,
            $product->compare_at_price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
            $product->stock,
        );

        foreach ($violations as $field => $message) {
            $missing[CoherentFinancingTerms::formFieldFor($field)] = $message;
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transitionTo(Product $product, ProductStatus $status, User $actor, array $extra = []): Product
    {
        if ($product->status === $status) {
            return $product;
        }

        return DB::transaction(function () use ($product, $status, $actor, $extra): Product {
            ModelState::adopt($product, ProductLock::acquire((int) $product->getKey())->getAttributes());

            if ($product->status === $status) {
                return $product;
            }

            $this->assertStepIsAllowed($product, $status);

            $from = $product->status;

            if ($status->countsTowardPlanLimit() && ! $from->countsTowardPlanLimit()) {
                $this->limits->assertCanCreate(UsageMetric::Products);
            }
            $columns = array_keys($extra);

            $before = ['status' => $product->status->value] + $this->valuesOf($product, $columns);

            $product->editedBy($actor)->forceFill(['status' => $status] + $extra)->save();

            $this->limits->adjust(
                UsageMetric::Products,
                (int) $status->countsTowardPlanLimit() - (int) $from->countsTowardPlanLimit(),
            );

            $this->audit(
                AuditEvent::ProductStatusChanged,
                subject: $product,
                actor: $actor,
                before: $before,
                after: ['status' => $status->value] + $this->valuesOf($product, $columns),
            );

            return $product;
        });
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function valuesOf(Product $product, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $value = $product->getAttribute($column);

            $values[$column] = $value instanceof CarbonInterface ? $value->toIso8601String() : $value;
        }

        return $values;
    }
}
