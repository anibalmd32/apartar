<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Queries\SpecificationGroupsQuery;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SpecificationGroupsQueryTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    public function test_it_offers_nothing_when_the_business_has_no_sheets(): void
    {
        $this->assertSame([], $this->query()->all());
    }

    public function test_it_offers_the_groups_already_used_in_alphabetical_order(): void
    {
        $this->sheetWith(['Motor', 'Acabado', 'Motor']);

        $this->assertSame(['Acabado', 'Motor'], $this->query()->all());
    }

    public function test_it_ignores_the_rows_without_a_group(): void
    {
        $this->sheetWith(['Motor', null, null]);

        $this->assertSame(['Motor'], $this->query()->all());
    }

    public function test_it_deduplicates_ignoring_capitalisation(): void
    {
        $this->sheetWith(['Motor', 'motor', 'MOTOR']);

        $this->assertCount(1, $this->query()->all());
    }

    public function test_it_offers_the_groups_of_every_product_including_the_current_one(): void
    {
        $first = Product::factory()->create(['sku' => 'A-1', 'slug' => 'a-1']);
        $second = Product::factory()->create(['sku' => 'A-2', 'slug' => 'a-2']);

        $this->rowIn($first, 'Motor');
        $this->rowIn($second, 'Acabado');

        $this->assertSame(['Acabado', 'Motor'], $this->query()->all());
    }

    public function test_it_never_offers_the_groups_of_another_organization(): void
    {
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($neighbour, function (): void {
            $this->sheetWith(['Del vecino']);
        });

        $this->actingAsOrganization($this->organization);
        $this->sheetWith(['Motor']);

        $this->assertSame(['Motor'], $this->query()->all());

        $this->assertSame(
            ['Del vecino'],
            $this->withinOrganization($neighbour, fn (): array => $this->query()->all()),
        );
    }

    /**
     * @param  list<string|null>  $groups
     */
    private function sheetWith(array $groups): void
    {
        $product = Product::factory()->create();

        foreach ($groups as $group) {
            $this->rowIn($product, $group);
        }
    }

    private function rowIn(Product $product, ?string $group): void
    {
        ProductSpecification::factory()->forProduct($product)->create([
            'group' => $group,
            'label' => 'Característica',
            'value' => 'Valor',
        ]);
    }

    private function query(): SpecificationGroupsQuery
    {
        return app(SpecificationGroupsQuery::class);
    }
}
