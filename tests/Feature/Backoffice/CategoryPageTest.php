<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Closure;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CategoryPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_the_page_carries_the_categories_in_order_with_their_count(): void
    {
        $this->inTenant(function (): void {
            $first = Category::factory()->named('Herramientas')->create(['position' => 1]);
            Category::factory()->named('Ropa')->create(['position' => 2]);

            Product::factory()->count(2)->inCategory($first)->create();
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/categorias')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/taxonomy/Categories')
                ->has('categories', 2)
                ->where('categories.0.name', 'Herramientas')
                ->where('categories.0.productsCount', 2)
                ->where('categories.1.name', 'Ropa')
                ->where('categories.1.productsCount', 0));
    }

    public function test_creating_a_category_derives_its_slug(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/categorias', ['name' => 'Electrodomésticos'])
            ->assertRedirect('/backoffice/categorias');

        $category = $this->inTenant(fn (): Category => Category::query()->sole());

        $this->assertSame('Electrodomésticos', $category->name);
        $this->assertSame('electrodomesticos', $category->slug);
        $this->assertSame(1, $category->position);
    }

    public function test_a_duplicate_name_in_the_same_organization_is_rejected(): void
    {
        $this->inTenant(fn () => Category::factory()->named('Ropa')->create());

        $this->actingAs($this->owner())
            ->post('/backoffice/categorias', ['name' => 'Ropa'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $this->inTenant(fn (): int => Category::query()->count()));
    }

    public function test_the_duplicate_check_ignores_capitalisation(): void
    {
        $this->inTenant(fn () => Category::factory()->named('Ropa')->create());

        $this->actingAs($this->owner())
            ->post('/backoffice/categorias', ['name' => 'ropa'])
            ->assertSessionHasErrors('name');
    }

    public function test_renaming_does_not_change_the_slug(): void
    {
        $category = $this->inTenant(fn (): Category => Category::factory()->named('Electrodomesitos')->create());

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Electrodomésticos'])
            ->assertRedirect('/backoffice/categorias');

        $category->refresh();

        $this->assertSame('Electrodomésticos', $category->name);
        $this->assertSame('electrodomesitos', $category->slug);
    }

    public function test_changing_the_slug_with_published_products_is_rejected(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Ropa')->create();

            Product::factory()->inCategory($category)->published()->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Ropa', 'slug' => 'ropa-nueva'])
            ->assertSessionHasErrors('slug');

        $this->assertSame('ropa', $category->refresh()->slug);
    }

    public function test_a_rejected_slug_change_saves_nothing(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Ropa')->create();

            Product::factory()->inCategory($category)->published()->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Ropa de niño', 'slug' => 'ropa-nino'])
            ->assertSessionHasErrors('slug');

        $category->refresh();

        $this->assertSame('Ropa', $category->name);
        $this->assertSame('ropa', $category->slug);
    }

    public function test_renaming_works_even_with_published_products(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Electrodomesitos')->create();

            Product::factory()->inCategory($category)->published()->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Electrodomésticos'])
            ->assertSessionHasNoErrors();

        $category->refresh();

        $this->assertSame('Electrodomésticos', $category->name);
        $this->assertSame('electrodomesitos', $category->slug);
    }

    public function test_resending_the_same_slug_is_not_a_change(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Ropa')->create();

            Product::factory()->inCategory($category)->published()->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Ropa de niño', 'slug' => 'ropa'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ropa de niño', $category->refresh()->name);
    }

    public function test_the_slug_changes_while_the_products_are_drafts(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Ropa')->create();

            Product::factory()->inCategory($category)->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->put("/backoffice/categorias/{$category->getKey()}", ['name' => 'Ropa', 'slug' => 'ropa-nino'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ropa-nino', $category->refresh()->slug);
    }

    public function test_deleting_a_category_with_products_is_rejected_with_the_count(): void
    {
        $category = $this->inTenant(function (): Category {
            $category = Category::factory()->named('Ropa')->create();

            Product::factory()->count(3)->inCategory($category)->create();

            return $category;
        });

        $this->actingAs($this->owner())
            ->delete("/backoffice/categorias/{$category->getKey()}")
            ->assertSessionHasErrors('category_id');

        $errors = session('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertStringContainsString('3 productos', (string) $errors->first('category_id'));
        $this->assertNotNull($this->inTenant(fn (): ?Category => Category::query()->find($category->getKey())));
    }

    public function test_reassigning_and_then_deleting_works(): void
    {
        [$from, $to] = $this->inTenant(function (): array {
            $from = Category::factory()->named('Ropa')->create();
            $to = Category::factory()->named('Textil')->create();

            Product::factory()->count(3)->inCategory($from)->create();

            return [$from, $to];
        });

        $owner = $this->owner();

        $this->actingAs($owner)
            ->post("/backoffice/categorias/{$from->getKey()}/reasignar", ['category_id' => $to->getKey()])
            ->assertRedirect('/backoffice/categorias');

        $this->actingAs($owner)
            ->delete("/backoffice/categorias/{$from->getKey()}")
            ->assertSessionHasNoErrors();

        $this->assertNull($this->inTenant(fn (): ?Category => Category::query()->find($from->getKey())));
        $this->assertSame(3, $this->inTenant(fn (): int => $to->products()->count()));
    }

    public function test_reassigning_to_the_same_category_is_rejected(): void
    {
        $category = $this->inTenant(fn (): Category => Category::factory()->named('Ropa')->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/categorias/{$category->getKey()}/reasignar", ['category_id' => $category->getKey()])
            ->assertSessionHasErrors('category_id');
    }

    public function test_a_category_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Ajena')->create(),
        );

        $owner = $this->owner();

        $this->actingAs($owner)->put("/backoffice/categorias/{$foreign->getKey()}", ['name' => 'Mía'])->assertNotFound();
        $this->actingAs($owner)->delete("/backoffice/categorias/{$foreign->getKey()}")->assertNotFound();
    }

    public function test_reassigning_to_a_foreign_category_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Ajena')->create(),
        );

        $own = $this->inTenant(fn (): Category => Category::factory()->named('Ropa')->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/categorias/{$own->getKey()}/reasignar", ['category_id' => $foreign->getKey()])
            ->assertNotFound();
    }

    public function test_the_order_is_persisted_and_stays_contiguous(): void
    {
        $ids = $this->inTenant(fn (): array => [
            (int) Category::factory()->named('A')->create()->getKey(),
            (int) Category::factory()->named('B')->create()->getKey(),
            (int) Category::factory()->named('C')->create()->getKey(),
        ]);

        $owner = $this->owner();

        $this->actingAs($owner)
            ->patch('/backoffice/categorias/orden', ['categories' => [$ids[2], $ids[0], $ids[1]]])
            ->assertRedirect('/backoffice/categorias');

        $this->assertSame(['C', 'A', 'B'], $this->orderedNames());

        $this->actingAs($owner)->delete("/backoffice/categorias/{$ids[0]}")->assertSessionHasNoErrors();

        $this->assertSame(['C', 'B'], $this->orderedNames());
        $this->assertSame([1, 2], $this->positions());
    }

    public function test_creating_categories_does_not_touch_the_plan(): void
    {
        $owner = $this->owner();

        foreach (['Una', 'Dos', 'Tres'] as $name) {
            $this->actingAs($owner)->post('/backoffice/categorias', ['name' => $name])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $this->inTenant(fn (): int => Category::query()->count()));
    }

    public function test_a_member_manages_the_taxonomy(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/categorias')->assertOk();
        $this->actingAs($member)->post('/backoffice/categorias', ['name' => 'Del empleado'])->assertSessionHasNoErrors();
    }

    public function test_the_taxonomy_is_blocked_while_the_organization_is_suspended(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $owner = $this->owner();

        $this->actingAs($owner)->get('/backoffice/categorias')->assertForbidden();
        $this->actingAs($owner)->post('/backoffice/categorias', ['name' => 'Nueva'])->assertForbidden();
    }

    /**
     * @return list<string>
     */
    private function orderedNames(): array
    {
        return $this->inTenant(fn (): array => Category::query()->ordered()->pluck('name')->all());
    }

    /**
     * @return list<int>
     */
    private function positions(): array
    {
        return $this->inTenant(fn (): array => Category::query()
            ->ordered()
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all());
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
