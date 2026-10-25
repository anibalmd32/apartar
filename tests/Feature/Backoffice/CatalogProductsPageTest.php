<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogProductsPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_it_saves_the_composition_and_its_order(): void
    {
        [$catalog, $products] = $this->inTenant(fn (): array => [
            Catalog::factory()->create(),
            Product::factory()->count(3)->create(),
        ]);

        $ids = $products->map(fn (Product $product): int => (int) $product->getKey())->values()->all();

        $this->actingAs($this->owner())
            ->from('/backoffice/productos')
            ->patch($this->url($catalog), ['products' => array_reverse($ids)])
            ->assertRedirect('/backoffice/productos')
            ->assertSessionHasNoErrors();

        $this->assertSame(array_reverse($ids), $this->orderedIdsIn($catalog));
    }

    public function test_a_product_of_another_organization_is_rejected_on_the_list(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Product => Product::factory()->create(),
        );

        $this->actingAs($this->owner())
            ->from('/backoffice/productos')
            ->patch($this->url($catalog), ['products' => [(int) $foreign->getKey()]])
            ->assertSessionHasErrors('products');

        $this->assertSame([], $this->orderedIdsIn($catalog));
    }

    public function test_emptying_depends_on_whether_the_catalog_is_published(): void
    {
        [$published, $draft, $product] = $this->inTenant(fn (): array => [
            Catalog::factory()->published()->create(),
            Catalog::factory()->create(),
            Product::factory()->published()->create(),
        ]);

        $owner = $this->owner();
        $ids = ['products' => [(int) $product->getKey()]];

        $this->actingAs($owner)->from('/backoffice/productos')->patch($this->url($published), $ids);
        $this->actingAs($owner)->from('/backoffice/productos')->patch($this->url($draft), $ids);

        $this->actingAs($owner)
            ->from('/backoffice/productos')
            ->patch($this->url($published), ['products' => []])
            ->assertSessionHasErrors('products');

        $this->actingAs($owner)
            ->from('/backoffice/productos')
            ->patch($this->url($draft), ['products' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $published->products()->count());
        $this->assertSame(0, $draft->products()->count());
    }

    public function test_a_malformed_list_is_rejected(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->actingAs($this->owner())
            ->from('/backoffice/productos')
            ->patch($this->url($catalog), ['products' => ['no-es-un-id']])
            ->assertSessionHasErrors('products.0');
    }

    public function test_a_member_composes_a_catalog(): void
    {
        [$catalog, $product] = $this->inTenant(fn (): array => [
            Catalog::factory()->create(),
            Product::factory()->create(),
        ]);

        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)
            ->from('/backoffice/productos')
            ->patch($this->url($catalog), ['products' => [(int) $product->getKey()]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $catalog->products()->count());
    }

    public function test_a_suspended_organization_cannot_compose_a_catalog(): void
    {
        $catalog = $this->inTenant(fn (): Catalog => Catalog::factory()->create());

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner())
            ->patch($this->url($catalog), ['products' => []])
            ->assertForbidden();
    }

    public function test_a_catalog_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Catalog => Catalog::factory()->create(),
        );

        $this->actingAs($this->owner())
            ->patch($this->url($foreign), ['products' => []])
            ->assertNotFound();
    }

    private function url(Catalog $catalog): string
    {
        return "/backoffice/catalogos/{$catalog->getKey()}/productos";
    }

    /**
     * @return list<int>
     */
    private function orderedIdsIn(Catalog $catalog): array
    {
        return DB::table('catalog_product')
            ->where('catalog_id', $catalog->getKey())
            ->orderBy('position')
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
