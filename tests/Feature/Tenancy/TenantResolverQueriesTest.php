<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TenantResolverQueriesTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Catalog $catalog;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['storefront_enabled' => true]);

        [$this->catalog, $this->product] = $this->withinOrganization($this->organization, function (): array {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();

            $catalog = Catalog::factory()->published()->create();
            $product = Product::factory()->published()->create();
            $catalog->products()->attach($product, ['position' => 1]);

            return [$catalog, $product];
        });
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function surfaces(): iterable
    {
        yield 'vitrina: la organización' => ['storefront', 'organizations', 1];
        yield 'catálogo: el catálogo' => ['catalog', 'catalogs', 1];
        yield 'catálogo: su organización' => ['catalog', 'organizations', 1];
        yield 'ficha: el catálogo' => ['product', 'catalogs', 1];
        yield 'ficha: su organización' => ['product', 'organizations', 1];
    }

    #[DataProvider('surfaces')]
    public function test_each_surface_reads_its_tenant_row_once(string $surface, string $table, int $expected): void
    {
        $url = match ($surface) {
            'storefront' => '/'.$this->organization->slug,
            'catalog' => '/c/'.$this->catalog->slug,
            default => '/c/'.$this->catalog->slug.'/p/'.$this->product->slug,
        };

        $this->withoutOrganization();
        $this->get($url)->assertOk();

        $this->withoutOrganization();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        $reads = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => preg_match('/^select \* from `'.$table.'` where/', (string) $query['query']) === 1,
        );

        $this->assertCount($expected, $reads, sprintf('%s leyó `%s` %d veces.', $url, $table, count($reads)));
    }

    public function test_the_bound_catalog_is_the_resolved_one_with_its_organization(): void
    {
        $this->withoutOrganization();

        $this->get('/c/'.$this->catalog->slug)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('catalog.slug', $this->catalog->slug)->where('catalog.business.name', $this->organization->name));
    }
}
