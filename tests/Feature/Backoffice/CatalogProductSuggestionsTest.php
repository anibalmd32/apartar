<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Http\Controllers\Backoffice\CatalogController;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogProductSuggestionsTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);

        $this->withoutOrganization();
    }

    public function test_it_suggests_the_most_recently_updated_products_that_are_not_in_the_catalog(): void
    {
        [$catalog, $expected] = $this->inTenant(function (): array {
            $catalog = Catalog::factory()->create();
            $inside = Product::factory()->create(['updated_at' => now()]);
            $catalog->products()->attach($inside, ['position' => 1]);

            Product::factory()->archived()->create(['updated_at' => now()->subMinute()]);
            $old = Product::factory()->create(['updated_at' => now()->subDays(3)]);
            $recent = Product::factory()->published()->create(['updated_at' => now()->subHour()]);
            $draft = Product::factory()->create(['updated_at' => now()->subDay()]);

            return [$catalog, [(int) $recent->getKey(), (int) $draft->getKey(), (int) $old->getKey()]];
        });

        $this->actingAs($this->owner())
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('suggestedProductIds', $expected));
    }

    public function test_the_suggestions_are_capped(): void
    {
        $catalog = $this->inTenant(function (): Catalog {
            Product::factory()->count(CatalogController::SUGGESTIONS + 3)->create();

            return Catalog::factory()->create();
        });

        $this->actingAs($this->owner())
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('suggestedProductIds', CatalogController::SUGGESTIONS));
    }

    public function test_products_of_another_organization_are_never_suggested(): void
    {
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): Product => Product::factory()->create(['updated_at' => now()->addMinute()]));
        [$catalog, $mine] = $this->inTenant(fn (): array => [Catalog::factory()->create(), Product::factory()->create()]);

        $this->actingAs($this->owner())
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('suggestedProductIds', [(int) $mine->getKey()])
                ->where('availableProducts', fn (mixed $rows): bool => is_iterable($rows)
                    && ! in_array($foreign->getKey(), array_column([...$rows], 'id'), true)));
    }

    public function test_the_options_carry_a_thumbnail_slot(): void
    {
        $catalog = $this->inTenant(function (): Catalog {
            Product::factory()->create();

            return Catalog::factory()->create();
        });

        $this->actingAs($this->owner())
            ->get("/backoffice/catalogos/{$catalog->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('availableProducts.0.thumbUrl', null));
    }

    public function test_the_creation_form_has_no_suggestions(): void
    {
        $this->actingAs($this->owner())
            ->get('/backoffice/catalogos/crear')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('suggestedProductIds', []));
    }

    private function owner(): User
    {
        return $this->inTenant(fn (): User => User::factory()->owner()->create());
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
