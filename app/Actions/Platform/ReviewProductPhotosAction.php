<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PhotoReviewAlreadyClosedException;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\ProductPhotoReview;
use App\Models\User;
use App\Notifications\ProductPhotosReviewedNotification;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

final class ReviewProductPhotosAction
{
    use LogsSensitiveActivity;

    public const REASON_MAX = 500;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws PhotoReviewAlreadyClosedException
     */
    public function approve(ProductPhotoReview $review, PlatformUser $operator): ProductPhotoReview
    {
        return $this->close($review, $operator, PhotoReviewStatus::Approved, null);
    }

    /**
     * @throws InvalidArgumentException
     * @throws PhotoReviewAlreadyClosedException
     */
    public function reject(ProductPhotoReview $review, string $reason, PlatformUser $operator): ProductPhotoReview
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException(sprintf('El motivo del rechazo es obligatorio y tiene como mucho %d caracteres.', self::REASON_MAX));
        }

        return $this->close($review, $operator, PhotoReviewStatus::Rejected, $reason);
    }

    private function close(ProductPhotoReview $review, PlatformUser $operator, PhotoReviewStatus $outcome, ?string $reason): ProductPhotoReview
    {
        $organization = Organization::query()->findOrFail($review->organization_id);

        $closed = $this->context->runFor($organization, fn (): ProductPhotoReview => DB::transaction(function () use ($review, $operator, $outcome, $reason): ProductPhotoReview {
            /** @var ProductPhotoReview $locked */
            $locked = ProductPhotoReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PhotoReviewStatus::Pending) {
                throw new PhotoReviewAlreadyClosedException($locked->status);
            }

            $locked->forceFill([
                'status' => $outcome,
                'rejection_reason' => $reason,
                'reviewed_at' => CarbonImmutable::now(),
                'reviewed_by' => $operator->getKey(),
            ])->save();

            $this->audit(AuditEvent::ProductPhotoReviewed, subject: $locked->product, actor: $operator, before: [
                'status' => PhotoReviewStatus::Pending->value,
            ], after: [
                'status' => $outcome->value,
                'reason' => $reason,
            ]);

            return $locked;
        }));

        PlatformAudit::record(
            $outcome === PhotoReviewStatus::Approved ? PlatformAudit::PRODUCT_PHOTOS_APPROVED : PlatformAudit::PRODUCT_PHOTOS_REJECTED,
            sprintf(
                '%s las fotos de «%s»%s',
                $outcome === PhotoReviewStatus::Approved ? 'Aprobó' : 'Rechazó',
                $closed->product->name,
                $reason === null ? '' : ': '.$reason,
            ),
            $operator,
            (int) $closed->organization_id,
            ['review_id' => $closed->getKey(), 'product_id' => $closed->product_id],
        );

        $this->notifyBusiness($organization, $closed);

        return $closed;
    }

    private function notifyBusiness(Organization $organization, ProductPhotoReview $review): void
    {
        try {
            $recipients = $this->context->runFor($organization, fn () => User::query()
                ->whereNull('disabled_at')
                ->where(fn ($query) => $query->where('role', OrganizationRole::Owner)->orWhere('id', $review->requested_by))
                ->orderBy('id')
                ->get()
                ->unique(fn (User $user): string => mb_strtolower($user->email)));

            Notification::send($recipients, new ProductPhotosReviewedNotification(
                $review->status === PhotoReviewStatus::Approved,
                $review->product->name,
                $review->rejection_reason,
                route('products.edit', $review->product_id),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
