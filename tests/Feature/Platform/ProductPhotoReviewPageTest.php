<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Catalog\PhotoReviewStatus;
use App\Domain\Catalog\PhotoSubject;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductPhotoReviewPageTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_it_lists_the_pending_reviews_of_every_business_oldest_first(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 12:00:00');
        $this->travelTo($now);
        [$cats] = $this->businessWithOwner();
        [$people] = $this->businessWithOwner();

        $this->reviewIn($cats, 'Gato persa', ['created_at' => $now->subHours(50), 'observation' => 'Un gato en un sofá.']);
        $this->reviewIn($people, 'Selfie', ['created_at' => $now->subHour(), 'subject' => PhotoSubject::Person, 'observation' => null]);
        $this->reviewIn($cats, 'Perro', ['status' => PhotoReviewStatus::Approved]);

        $this->asOperator();

        $this->get('/plataforma/revisiones')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/reviews/Index')
                ->has('reviews', 2)
                ->where('reviews.0.product.name', 'Gato persa')
                ->where('reviews.0.organization.name', $cats->name)
                ->where('reviews.0.observation', 'Un gato en un sofá.')
                ->where('reviews.0.isLate', true)
                ->where('reviews.1.product.name', 'Selfie')
                ->where('reviews.1.subject', 'una persona')
                ->where('reviews.1.isLate', false)
                ->where('platformOperator.pendingPhotoReviews', 2));
    }

    public function test_an_operator_approves_and_rejects_from_the_page(): void
    {
        [$business] = $this->businessWithOwner();
        $approve = $this->reviewIn($business, 'Gato persa');
        $reject = $this->reviewIn($business, 'Paisaje');

        $this->asOperator();

        $this->post("/plataforma/revisiones/{$approve->getKey()}/aprobar")->assertSessionHasNoErrors()->assertRedirect();
        $this->post("/plataforma/revisiones/{$reject->getKey()}/rechazar", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/plataforma/revisiones/{$reject->getKey()}/rechazar", ['reason' => 'No es un producto.'])->assertSessionHasNoErrors();
        $this->post("/plataforma/revisiones/{$approve->getKey()}/rechazar", ['reason' => 'Tarde.'])
            ->assertSessionHasErrors(['reason' => 'Esta revisión ya fue aprobada por otra persona.']);
        $this->post('/plataforma/revisiones/999999/aprobar')->assertNotFound();

        $this->assertSame(PhotoReviewStatus::Approved, $approve->fresh()?->status);
        $this->assertSame('No es un producto.', $reject->fresh()?->rejection_reason);
    }

    public function test_a_business_user_cannot_reach_it(): void
    {
        [, $owner] = $this->businessWithOwner();

        $this->actingAs($owner)->get('/plataforma/revisiones')->assertRedirect();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function reviewIn(Organization $organization, string $productName, array $state = []): ProductPhotoReview
    {
        $review = $this->withinOrganization($organization, fn (): ProductPhotoReview => ProductPhotoReview::factory()->create([
            'product_id' => Product::factory()->create(['name' => $productName])->getKey(),
        ] + $state));
        $this->withoutOrganization();

        return $review;
    }
}
