<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Account\FeedFilters;
use App\Domain\Account\FeedQuery;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use App\Models\Product;
use App\Support\FullTextTerm;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FeedQueryTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Organization $ferreteria;

    private Organization $boutique;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 12:00:00');
        $this->account = Account::factory()->create();
        $this->ferreteria = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        $this->boutique = Organization::factory()->create(['name' => 'Boutique Sur']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_product_of_a_catalog_not_followed_never_appears(): void
    {
        $followed = $this->catalogIn($this->ferreteria, ['Taladro' => '2026-10-20 10:00:00']);
        $this->catalogIn($this->ferreteria, ['Martillo' => '2026-10-21 10:00:00']);
        $this->catalogIn($this->boutique, ['Vestido' => '2026-10-21 11:00:00']);
        $this->follow($followed);

        $this->assertSame(['Taladro'], $this->names());
    }

    public function test_newest_first_and_deduplicated_within_a_business(): void
    {
        $first = $this->catalogIn($this->ferreteria, ['Taladro' => '2026-10-10 10:00:00', 'Sierra' => '2026-10-18 10:00:00']);
        $second = $this->catalogIn($this->ferreteria, [], name: 'Ofertas');
        $this->attach($second, 'Taladro', '2026-10-19 10:00:00');
        $dress = $this->catalogIn($this->boutique, ['Vestido' => '2026-10-15 10:00:00']);
        array_map($this->follow(...), [$first, $second, $dress]);

        $items = $this->feed()['items'];

        $this->assertSame(['Taladro', 'Sierra', 'Vestido'], array_map(fn (array $item): string => $item['product']->name, $items));
        $this->assertSame('Ofertas', $items[0]['catalog']->name);
        $this->assertSame('Boutique Sur', $items[2]['organization']->name);
    }

    public function test_is_new_at_the_fourteen_day_boundary(): void
    {
        $catalog = $this->catalogIn($this->ferreteria, ['Justo' => '2026-10-07 12:00:00', 'Pasado' => '2026-10-07 11:59:59']);
        $this->follow($catalog);

        $isNew = array_column(array_map(fn (array $item): array => [$item['product']->name, $item['isNew']], $this->feed()['items']), 1, 0);

        $this->assertTrue($isNew['Justo']);
        $this->assertFalse($isNew['Pasado']);
    }

    public function test_unpublished_products_and_closed_catalogs_leave_the_feed_but_muting_does_not(): void
    {
        $open = $this->catalogIn($this->ferreteria, ['Taladro' => '2026-10-20 10:00:00', 'Retirado' => '2026-10-20 11:00:00']);
        $closed = $this->catalogIn($this->boutique, ['Vestido' => '2026-10-20 12:00:00']);
        $this->follow($open, muted: true);
        $this->follow($closed);

        $this->withinOrganization($this->ferreteria, fn () => Product::query()->where('name', 'Retirado')->update(['status' => 'draft']));
        $this->withinOrganization($this->boutique, fn () => $closed->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();

        $this->assertSame(['Taladro'], $this->names());
    }

    public function test_cursor_pages_cover_everything_once_without_counting(): void
    {
        $this->follow($this->catalogIn($this->ferreteria, ['A' => '2026-10-20 10:00:00', 'C' => '2026-10-20 08:00:00', 'E' => '2026-10-20 06:00:00']));
        $this->follow($this->catalogIn($this->boutique, ['B' => '2026-10-20 09:00:00', 'D' => '2026-10-20 08:00:00']));

        $counts = 0;
        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            $counts += preg_match('/count\s*\(/i', $query->sql) === 1 ? 1 : 0;
        });

        $seen = [];
        $cursor = null;

        do {
            $page = app(FeedQuery::class)->forAccount($this->account, new FeedFilters, $cursor, limit: 2);
            array_push($seen, ...array_map(fn (array $item): string => $item['product']->name, $page['items']));
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        $this->assertSame(['A', 'B', 'D', 'C', 'E'], $seen);
        $this->assertSame(0, $counts);
    }

    public function test_filters_by_catalog_and_by_text(): void
    {
        $tools = $this->catalogIn($this->ferreteria, ['Taladro percutor' => '2026-10-20 10:00:00', 'Sierra' => '2026-10-20 09:00:00']);
        $dresses = $this->catalogIn($this->boutique, ['Vestido' => '2026-10-20 11:00:00']);
        $this->follow($tools);
        $this->follow($dresses);

        $this->assertSame(['Vestido'], $this->names(new FeedFilters(catalogSlug: $dresses->slug)));
        $this->assertSame(['Taladro percutor'], $this->names(new FeedFilters(term: FullTextTerm::sanitize('taladro'))));
    }

    public function test_without_subscriptions_the_feed_is_empty(): void
    {
        $this->catalogIn($this->ferreteria, ['Taladro' => '2026-10-20 10:00:00']);

        $this->assertSame(['items' => [], 'nextCursor' => null], app(FeedQuery::class)->forAccount($this->account, new FeedFilters));
    }

    /**
     * @param  array<string, string>  $products
     */
    private function catalogIn(Organization $organization, array $products, string $name = 'Catálogo'): Catalog
    {
        $catalog = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create(['name' => $name]));

        foreach ($products as $productName => $at) {
            $this->attach($catalog, $productName, $at);
        }

        $this->withoutOrganization();

        return $catalog;
    }

    private function attach(Catalog $catalog, string $productName, string $at): void
    {
        $organization = Organization::query()->findOrFail($catalog->organization_id);

        $this->withinOrganization($organization, function () use ($catalog, $productName, $at): void {
            $product = Product::query()->where('name', $productName)->first()
                ?? Product::factory()->published()->create(['name' => $productName, 'published_at' => '2026-09-01 00:00:00']);
            $catalog->products()->attach($product, ['position' => 1]);
            DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->where('product_id', $product->getKey())->update(['created_at' => $at]);
        });

        $this->withoutOrganization();
    }

    private function follow(Catalog $catalog, bool $muted = false): void
    {
        CatalogSubscription::factory()->create([
            'account_id' => $this->account->getKey(),
            'catalog_id' => $catalog->getKey(),
            'muted_at' => $muted ? now() : null,
        ]);
    }

    /**
     * @return array{items: list<array{product: Product, catalog: Catalog, organization: Organization, enteredAt: CarbonImmutable, isNew: bool}>, nextCursor: string|null}
     */
    private function feed(?FeedFilters $filters = null): array
    {
        $this->withoutOrganization();

        return app(FeedQuery::class)->forAccount($this->account, $filters ?? new FeedFilters);
    }

    /**
     * @return list<string>
     */
    private function names(?FeedFilters $filters = null): array
    {
        return array_map(fn (array $item): string => $item['product']->name, $this->feed($filters)['items']);
    }
}
