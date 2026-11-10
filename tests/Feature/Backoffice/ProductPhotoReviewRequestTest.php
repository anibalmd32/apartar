<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\PhotoReviewStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPhotoReview;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductPhotoReviewRequestTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_a_rejected_product_asks_for_another_review_from_its_page(): void
    {
        $product = $this->productWithReview(rejected: true);

        $this->actingAs($this->owner)
            ->from('/backoffice/productos/'.$product->getKey().'/editar')
            ->post('/backoffice/productos/'.$product->getKey().'/revision-fotos')
            ->assertSessionHasNoErrors()
            ->assertRedirect('/backoffice/productos/'.$product->getKey().'/editar');

        $this->assertSame(PhotoReviewStatus::Pending, $this->withinOrganization($this->organization, fn () => $product->photoReview()->first()?->status));
    }

    public function test_a_product_already_in_review_cannot_ask_again(): void
    {
        $product = $this->productWithReview(rejected: false);

        $this->actingAs($this->owner)
            ->post('/backoffice/productos/'.$product->getKey().'/revision-fotos')
            ->assertSessionHasErrors(['photo_review' => 'Solo se puede pedir otra revisión después de que Apartar rechace las fotos.']);
    }

    public function test_the_list_marks_the_products_waiting_for_or_rejected_by_the_review(): void
    {
        $this->productWithReview(rejected: false, name: 'Gato');
        $this->productWithReview(rejected: true, name: 'Paisaje');
        $this->withinOrganization($this->organization, fn () => Product::factory()->create(['name' => 'Licuadora']));
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->get('/backoffice/productos?sort=name')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.name', 'Gato')
                ->where('rows.0.photoReview', 'pending')
                ->where('rows.1.photoReview', null)
                ->where('rows.2.name', 'Paisaje')
                ->where('rows.2.photoReview', 'rejected'));
    }

    private function productWithReview(bool $rejected, string $name = 'Gato persa'): Product
    {
        $product = $this->withinOrganization($this->organization, function () use ($rejected, $name): Product {
            $product = Product::factory()->create(['name' => $name]);
            $factory = ProductPhotoReview::factory();

            ($rejected ? $factory->rejected() : $factory)->create(['product_id' => $product->getKey()]);

            return $product;
        });
        $this->withoutOrganization();

        return $product;
    }
}
