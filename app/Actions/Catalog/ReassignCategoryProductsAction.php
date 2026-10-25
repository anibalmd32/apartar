<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReassignCategoryProductsAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function handle(Category $from, int $toCategoryId, User $actor): int
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        /** @var Category $to */
        $to = Category::query()->findOrFail($toCategoryId);

        if ($to->is($from)) {
            return 0;
        }

        return DB::transaction(function () use ($from, $to, $actor): int {
            return Product::withTrashed()
                ->where('category_id', $from->getKey())
                ->update(['category_id' => $to->getKey(), 'updated_by' => $actor->getKey()]);
        });
    }
}
