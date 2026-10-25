<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductNotAvailableException;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductNotAvailableExceptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
    }

    public function test_an_archived_product_cannot_be_reserved(): void
    {
        $product = Product::factory()->archived()->create(['name' => 'Taladro Bosch']);

        try {
            ProductNotAvailableException::guard($product);

            $this->fail('Apartar un archivado tenía que rechazarse.');
        } catch (ProductNotAvailableException $exception) {
            $this->assertTrue($exception->product->is($product));
            $this->assertStringContainsString('Taladro Bosch', $exception->getMessage());
            $this->assertStringContainsString('ya no está disponible', $exception->getMessage());
            $this->assertSame(422, ProductNotAvailableException::STATUS);
        }
    }

    public function test_a_draft_product_cannot_be_reserved_either(): void
    {
        $this->expectException(ProductNotAvailableException::class);

        ProductNotAvailableException::guard(Product::factory()->create());
    }

    public function test_the_message_does_not_leak_which_state_it_was_in(): void
    {
        $draft = Product::factory()->create(['name' => 'Sierra']);
        $archived = Product::factory()->archived()->create(['name' => 'Sierra']);

        $this->assertSame(
            ProductNotAvailableException::for($draft)->getMessage(),
            ProductNotAvailableException::for($archived)->getMessage(),
        );
    }

    public function test_an_out_of_stock_published_product_passes_this_guard(): void
    {
        $product = Product::factory()->published()->outOfStock()->create();

        ProductNotAvailableException::guard($product);

        $this->assertTrue($product->status->canBeReserved());
    }

    public function test_a_published_product_passes_this_guard(): void
    {
        $scheduled = Product::factory()->scheduled()->create();

        ProductNotAvailableException::guard(Product::factory()->published()->create());
        ProductNotAvailableException::guard($scheduled);

        $this->assertTrue($scheduled->status->canBeReserved());
    }
}
