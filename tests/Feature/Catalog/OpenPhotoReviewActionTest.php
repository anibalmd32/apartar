<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\OpenPhotoReviewAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Domain\Catalog\ProductStatus;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use App\Models\User;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OpenPhotoReviewActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_opens_a_pending_review_and_audits_it(): void
    {
        $product = Product::factory()->create();

        $review = $this->opener()->handle($product, $this->actor, PhotoSubject::Animal, 'Un gato en un sofá.');

        $this->assertSame(PhotoReviewStatus::Pending, $review->status);
        $this->assertSame((int) $this->actor->getKey(), $review->requested_by);
        $this->assertTrue($product->fresh()?->photoReviewBlocksPublishing());

        $entry = AuditEntry::query()->where('event', AuditEvent::ProductPhotoReviewRequested->value)->sole();
        $this->assertSame('animal', $entry->properties['changed']['subject']['after'] ?? null);
    }

    public function test_a_published_product_goes_back_to_draft_while_it_is_reviewed(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published, 'published_at' => now()]);

        $this->opener()->handle($product, $this->actor, PhotoSubject::Person, '');

        $this->assertSame(ProductStatus::Draft, $product->fresh()?->status);
        $this->assertNull(ProductPhotoReview::query()->sole()->observation);
    }

    public function test_a_pending_review_is_not_duplicated(): void
    {
        $product = Product::factory()->create();

        $first = $this->opener()->handle($product, $this->actor, PhotoSubject::Animal, 'Un gato.');
        $second = $this->opener()->handle($product, $this->actor, PhotoSubject::Other, 'Un paisaje.');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ProductPhotoReview::query()->count());
    }

    public function test_after_a_rejection_a_new_review_can_be_opened(): void
    {
        $product = Product::factory()->create();
        ProductPhotoReview::factory()->rejected()->create(['product_id' => $product->getKey()]);

        $this->opener()->handle($product, $this->actor, PhotoSubject::Animal, 'Un perro.');

        $this->assertSame(PhotoReviewStatus::Pending, $product->fresh()?->photoReview?->status);
        $this->assertSame(2, ProductPhotoReview::query()->count());
    }

    public function test_a_pass_that_does_not_exist_opens_nothing(): void
    {
        $product = Product::factory()->create();

        $this->assertNull($this->opener()->redeem($product, $this->actor, 'inventado'));
        $this->assertNull($this->opener()->redeem($product, $this->actor, null));
        $this->assertSame(0, ProductPhotoReview::query()->count());
    }

    private function opener(): OpenPhotoReviewAction
    {
        return app(OpenPhotoReviewAction::class);
    }
}
