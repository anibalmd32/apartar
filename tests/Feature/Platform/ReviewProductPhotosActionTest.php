<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\ReviewProductPhotosAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PhotoReviewAlreadyClosedException;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use App\Support\Auditing\PlatformAudit;
use InvalidArgumentException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReviewProductPhotosActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    private Organization $organization;

    private ProductPhotoReview $review;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization] = $this->businessWithOwner();
        $this->review = $this->withinOrganization($this->organization, fn (): ProductPhotoReview => ProductPhotoReview::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Gato persa'])->getKey(),
        ]));
        $this->withoutOrganization();
    }

    public function test_approving_lets_the_business_publish_and_is_audited_on_both_sides(): void
    {
        $operator = $this->operator();

        $approved = $this->reviews()->approve($this->review, $operator);

        $this->assertSame(PhotoReviewStatus::Approved, $approved->status);
        $this->assertSame((int) $operator->getKey(), $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);

        $this->withinOrganization($this->organization, function (): void {
            $this->assertFalse(Product::query()->sole()->photoReviewBlocksPublishing());

            $entry = AuditEntry::query()->where('event', AuditEvent::ProductPhotoReviewed->value)->sole();
            $this->assertSame('approved', $entry->properties['changed']['status']['after'] ?? null);
        });

        $activity = PlatformActivity::query()->where('action', PlatformAudit::PRODUCT_PHOTOS_APPROVED)->sole();
        $this->assertStringContainsString('Gato persa', (string) $activity->description);
    }

    public function test_rejecting_requires_a_reason_and_keeps_the_product_blocked(): void
    {
        $operator = $this->operator();

        $this->assertThrows(fn () => $this->reviews()->reject($this->review, '   ', $operator), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->reviews()->reject($this->review, str_repeat('a', 501), $operator), InvalidArgumentException::class);

        $rejected = $this->reviews()->reject($this->review, '  No vendemos animales.  ', $operator);

        $this->assertSame(PhotoReviewStatus::Rejected, $rejected->status);
        $this->assertSame('No vendemos animales.', $rejected->rejection_reason);
        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => Product::query()->sole()->photoReviewBlocksPublishing()));
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::PRODUCT_PHOTOS_REJECTED)->count());
    }

    public function test_the_first_operator_to_decide_wins(): void
    {
        $this->reviews()->approve($this->review, $this->operator());

        try {
            $this->reviews()->reject($this->review, 'Tarde.', $this->operator());

            $this->fail('La segunda decisión tenía que rechazarse.');
        } catch (PhotoReviewAlreadyClosedException $exception) {
            $this->assertSame('Esta revisión ya fue aprobada por otra persona.', $exception->getMessage());
        }

        $this->assertSame(PhotoReviewStatus::Approved, $this->review->fresh()?->status);
    }

    private function reviews(): ReviewProductPhotosAction
    {
        return app(ReviewProductPhotosAction::class);
    }
}
