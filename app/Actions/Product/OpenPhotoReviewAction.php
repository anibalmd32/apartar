<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use App\Models\User;
use App\Support\Ai\HeldCopySuggestions;
use App\Support\Auditing\LogsSensitiveActivity;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class OpenPhotoReviewAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ChangeProductStatusAction $status,
        private readonly HeldCopySuggestions $held,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(Product $product, User $actor, PhotoSubject $subject, string $observation): ProductPhotoReview
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($product, $actor, $subject, $observation): ProductPhotoReview {
            $locked = ProductLock::acquire((int) $product->getKey());
            $current = $locked->photoReview;

            if ($current instanceof ProductPhotoReview && $current->status === PhotoReviewStatus::Pending) {
                return $current;
            }

            $review = new ProductPhotoReview;
            $review->forceFill([
                'product_id' => $locked->getKey(),
                'requested_by' => $actor->getKey(),
                'subject' => $subject,
                'observation' => $observation === '' ? null : $observation,
                'status' => PhotoReviewStatus::Pending,
            ])->save();

            if ($locked->status === ProductStatus::Published) {
                $this->status->unpublish($locked, $actor);
            }

            $this->audit(AuditEvent::ProductPhotoReviewRequested, subject: $locked, actor: $actor, after: [
                'subject' => $subject->value,
                'observation' => $review->observation,
            ]);

            $product->setRelation('photoReview', $review);

            return $review;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function again(Product $product, User $actor): ProductPhotoReview
    {
        $rejected = $product->photoReview()->first();

        if (! $rejected instanceof ProductPhotoReview || $rejected->status !== PhotoReviewStatus::Rejected) {
            throw new DomainException('Solo se puede pedir otra revisión después de que Apartar rechace las fotos.');
        }

        return $this->handle($product, $actor, $rejected->subject, mb_substr(
            sprintf('El negocio cambió las fotos y pidió otra revisión. Motivo del rechazo anterior: %s', $rejected->rejection_reason ?? ''),
            0,
            SuggestProductCopyAction::MAX_OBSERVATION_LENGTH,
        ));
    }

    /**
     * @throws AuthorizationException
     */
    public function redeem(Product $product, User $actor, ?string $pass): ?ProductPhotoReview
    {
        if ($pass === null || $pass === '') {
            return null;
        }

        $granted = $this->held->redeemPass($pass, (int) $this->context->organizationId(), (int) $actor->getKey());

        return $granted === null ? null : $this->handle($product, $actor, $granted['subject'], $granted['observation']);
    }
}
