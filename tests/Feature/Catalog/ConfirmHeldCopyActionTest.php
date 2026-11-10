<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ConfirmHeldCopyAction;
use App\Actions\Product\OpenPhotoReviewAction;
use App\Domain\Catalog\CopywriterUnavailableException;
use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Domain\Catalog\ProductStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use App\Models\User;
use App\Support\Ai\HeldCopySuggestions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ConfirmHeldCopyActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_confirming_a_new_product_returns_the_copy_and_a_pass_for_the_review(): void
    {
        $token = $this->hold(null);

        $confirmed = $this->confirm()->handle($this->actor, $token);

        $this->assertSame('<p>Gato persa.</p>', $confirmed['description']);
        $this->assertSame(['Color blanco'], $confirmed['characteristics']);
        $this->assertSame($token, $confirmed['review_pass']);
        $this->assertSame(0, ProductPhotoReview::query()->count());

        $product = Product::factory()->create();
        $review = app(OpenPhotoReviewAction::class)->redeem($product, $this->actor, $token);

        $this->assertNotNull($review);
        $this->assertSame(PhotoSubject::Animal, $review->subject);
        $this->assertSame('Un gato blanco.', $review->observation);
        $this->assertNull(app(OpenPhotoReviewAction::class)->redeem($product, $this->actor, $token), 'el pase se usa una sola vez.');
    }

    public function test_confirming_an_existing_product_opens_its_review_at_once(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published, 'published_at' => now()]);

        $confirmed = $this->confirm()->handle($this->actor, $this->hold((int) $product->getKey()));

        $this->assertNull($confirmed['review_pass']);
        $product->refresh();

        $this->assertSame(PhotoReviewStatus::Pending, $product->photoReview?->status);
        $this->assertSame(ProductStatus::Draft, $product->status);
    }

    public function test_a_suggestion_is_released_only_once_and_only_to_whoever_asked_for_it(): void
    {
        $token = $this->hold(null);
        $colleague = User::factory()->owner()->create();

        $this->assertThrows(fn () => $this->confirm()->handle($colleague, $token), CopywriterUnavailableException::class);

        $this->confirm()->handle($this->actor, $token);

        $this->assertThrows(fn () => $this->confirm()->handle($this->actor, $token), CopywriterUnavailableException::class);
    }

    private function hold(?int $productId): string
    {
        return app(HeldCopySuggestions::class)->hold(
            (int) $this->organization->getKey(),
            (int) $this->actor->getKey(),
            $productId,
            PhotoSubject::Animal,
            'Un gato blanco.',
            ['description' => '<p>Gato persa.</p>', 'characteristics' => ['Color blanco'], 'tags' => ['mascotas']],
        );
    }

    private function confirm(): ConfirmHeldCopyAction
    {
        return app(ConfirmHeldCopyAction::class);
    }
}
