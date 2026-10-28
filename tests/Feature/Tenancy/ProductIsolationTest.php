<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductIsolationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Organization $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->subscribeWithoutLimits($this->organization);
        $this->subscribeWithoutLimits($this->neighbour);

        $this->withoutOrganization();
    }

    public function test_the_same_sku_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization(
            $this->neighbour,
            fn () => Product::factory()->create(['sku' => 'BOSCH-01', 'slug' => 'taladro']),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Product::withoutTenantScope()->count());
        $this->assertSame(1, $this->countIn($this->organization));
        $this->assertSame(1, $this->countIn($this->neighbour));
    }

    public function test_the_same_slug_is_allowed_in_two_organizations(): void
    {
        $this->withinOrganization(
            $this->neighbour,
            fn () => Product::factory()->create(['sku' => 'VECINO', 'slug' => 'taladro-bosch']),
        );

        $own = $this->withinOrganization(
            $this->organization,
            fn (): Product => Product::factory()->create(['sku' => 'MIO', 'slug' => 'taladro-bosch']),
        );

        $this->assertSame('taladro-bosch', $own->slug);
        $this->assertSame(2, Product::withoutTenantScope()->where('slug', 'taladro-bosch')->count());
    }

    public function test_the_listing_never_shows_the_neighbours_products(): void
    {
        $this->withinOrganization($this->neighbour, fn () => Product::factory()->create(['name' => 'Del vecino']));
        $this->withinOrganization($this->organization, fn () => Product::factory()->create(['name' => 'Mío']));

        $this->actingAs($this->owner())
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.name', 'Mío'));

        $this->assertTrue($this->hasNeighbourOrganization());
    }

    public function test_tagging_with_the_neighbours_tag_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
                'tag_ids' => [$foreign->getKey()],
            ])
            ->assertSessionHasErrors('tag_ids');

        $this->assertSame(0, $this->countIn($this->organization));
        $this->assertSame(0, DB::table('product_tag')->count());
    }

    public function test_assigning_the_neighbours_category_is_rejected(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbour,
            fn (): Category => Category::factory()->named('Del vecino')->create(),
        );

        $this->actingAs($this->owner())
            ->post('/backoffice/productos', [
                'name' => 'Taladro Bosch',
                'sku' => 'BOSCH-01',
                'description' => 'Un taladro.',
                'price' => '250.00',
                'compare_at_price' => '',
                'down_payment' => '50.00',
                'installments_count' => 4,
                'frequency_days' => 15,
                'stock' => 10,
                'category_id' => $foreign->getKey(),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->countIn($this->organization));
    }

    public function test_the_neighbours_specification_rows_are_unreachable(): void
    {
        $foreign = $this->withinOrganization($this->neighbour, function (): ProductSpecification {
            $product = Product::factory()->create();

            return ProductSpecification::factory()->forProduct($product)->create(['text' => 'Del vecino']);
        });

        $this->actingAsOrganization($this->organization);

        $this->assertNull(ProductSpecification::query()->find($foreign->getKey()));
        $this->assertSame(0, ProductSpecification::query()->count());
    }

    private function countIn(Organization $organization): int
    {
        return $this->withinOrganization($organization, fn (): int => Product::query()->count());
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }
}
