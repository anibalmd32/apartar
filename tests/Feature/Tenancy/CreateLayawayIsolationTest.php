<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Customer\TermsConsent;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreateLayawayIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_everything_the_layaway_writes_belongs_to_the_catalog_organization(): void
    {
        [$ours, $catalog, $product] = $this->businessWithCatalog();
        $theirs = $this->neighbourOrganization();

        $layaway = $this->create($catalog, $product);

        $this->assertSame((int) $ours->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('organization_id'));
        $this->assertSame((int) $ours->getKey(), (int) DB::table('customers')->where('id', $layaway->customer_id)->value('organization_id'));
        $this->assertSame(1, (int) DB::table('usage_counters')->where('organization_id', $ours->getKey())->value('value'));

        $this->assertSame(0, $this->withinOrganization($theirs, fn (): int => Layaway::query()->count()));
        $this->assertSame(0, $this->withinOrganization($theirs, fn (): int => Customer::query()->count()));
        $this->assertSame(0, DB::table('usage_counters')->where('organization_id', $theirs->getKey())->count());
    }

    public function test_a_neighbour_product_cannot_be_reserved_through_our_catalog(): void
    {
        [, $catalog] = $this->businessWithCatalog();
        $neighbours = $this->withinOrganization($this->neighbourOrganization(), fn (): Product => Product::factory()->published()->create(['stock' => 4]));

        try {
            $this->create($catalog, $neighbours);
            $this->fail('Se apartó el producto de otra organización.');
        } catch (ProductNotAvailableException) {
        }

        $this->assertSame(4, (int) DB::table('products')->where('id', $neighbours->getKey())->value('stock'));
        $this->assertSame(0, DB::table('layaways')->count());
    }

    /**
     * @return array{0: Organization, 1: Catalog, 2: Product}
     */
    private function businessWithCatalog(): array
    {
        $organization = Organization::factory()->create();

        [$catalog, $product] = $this->withinOrganization($organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();
            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create(['stock' => 4]);
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });

        $this->withoutOrganization();

        return [$organization, $catalog, $product];
    }

    private function create(Catalog $catalog, Product $product): Layaway
    {
        return app(CreateLayawayAction::class)->handle(new CreateLayawayData(
            catalog: $catalog,
            product: $product,
            quantity: 1,
            contact: CustomerContactData::fromInput('V12345678', 'María González', '0414-1234567', null),
            consent: TermsConsent::acceptedNowFrom('190.202.10.5'),
            idempotencyKey: (string) Str::uuid(),
        ));
    }
}
