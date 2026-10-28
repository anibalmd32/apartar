<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PublishProductsAction
{
    public function __construct(
        private TenantContext $context,
        private ChangeProductStatusAction $status,
    ) {}

    /**
     * @param  list<int>  $ids
     *
     * @throws AuthorizationException
     */
    public function handle(array $ids, User $actor): BulkPublishResult
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $ids = array_values(array_unique($ids));

        $drafts = Product::query()
            ->whereKey($ids)
            ->where('status', ProductStatus::Draft)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $published = [];
        $failed = [];

        foreach ($drafts as $product) {
            try {
                $this->status->publish($product, $actor);
                $published[] = $product->name;
            } catch (IncompleteProductException $exception) {
                $failed[] = ['name' => $product->name, 'reason' => 'le falta: '.$exception->missingSummary()];
            } catch (ValidationException) {
                $failed[] = ['name' => $product->name, 'reason' => 'no está en borrador'];
            }
        }

        return new BulkPublishResult($published, $failed, count($ids) - $drafts->count());
    }
}
