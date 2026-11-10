<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\CopywriterUnavailableException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use App\Support\Ai\HeldCopySuggestions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ConfirmHeldCopyAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HeldCopySuggestions $held,
        private readonly OpenPhotoReviewAction $reviews,
    ) {}

    /**
     * @return array{description: string, characteristics: list<string>, tags: list<string>, review_pass: string|null}
     *
     * @throws AuthorizationException
     * @throws CopywriterUnavailableException
     */
    public function handle(User $actor, string $token): array
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $organizationId = (int) $this->context->organizationId();
        $released = $this->held->release($token, $organizationId, (int) $actor->getKey());

        if ($released === null) {
            throw CopywriterUnavailableException::suggestionExpired();
        }

        $product = $released['product_id'] === null ? null : Product::query()->find($released['product_id']);

        if ($product instanceof Product) {
            $this->reviews->handle($product, $actor, $released['subject'], $released['observation']);

            return $released['copy'] + ['review_pass' => null];
        }

        $this->held->grantPass($token, $organizationId, (int) $actor->getKey(), $released['subject'], $released['observation']);

        return $released['copy'] + ['review_pass' => $token];
    }
}
