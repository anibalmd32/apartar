<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CategoryOrder;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReorderCategoriesAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CategoryOrder $order,
    ) {}

    /**
     * @param  list<int>  $orderedIds
     *
     * @throws AuthorizationException
     */
    public function handle(array $orderedIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        DB::transaction(function () use ($orderedIds): void {
            $this->order->apply($orderedIds);
        });
    }
}
