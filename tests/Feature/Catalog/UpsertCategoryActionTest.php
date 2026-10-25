<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\UpsertCategoryAction;
use App\Domain\Catalog\CategoryInUseException;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpsertCategoryActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_creates_the_category_with_a_derived_slug_at_the_end(): void
    {
        $this->upsert()->handle('Herramientas', null, $this->actor);
        $second = $this->upsert()->handle('Electrodomésticos', null, $this->actor);

        $this->assertSame('electrodomesticos', $second->slug);
        $this->assertSame(2, $second->position);
    }

    public function test_a_requested_slug_is_normalised(): void
    {
        $category = $this->upsert()->handle('Ropa', 'Ropa De Niño', $this->actor);

        $this->assertSame('ropa-de-nino', $category->slug);
    }

    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        $this->upsert()->handle('Ropa niño', null, $this->actor);
        $second = $this->upsert()->handle('Ropa-niño', null, $this->actor);

        $this->assertSame('ropa-nino', Category::query()->where('name', 'Ropa niño')->sole()->slug);
        $this->assertSame('ropa-nino-2', $second->slug);
    }

    public function test_a_name_without_letters_still_gets_a_slug(): void
    {
        $first = $this->upsert()->handle('★', null, $this->actor);
        $second = $this->upsert()->handle('☆', null, $this->actor);

        $this->assertNotSame('', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_renaming_keeps_the_slug(): void
    {
        $category = $this->upsert()->handle('Electrodomesitos', null, $this->actor);

        $this->upsert()->handle('Electrodomésticos', null, $this->actor, $category);

        $this->assertSame('Electrodomésticos', $category->refresh()->name);
        $this->assertSame('electrodomesitos', $category->slug);
    }

    public function test_a_locked_slug_rejects_the_whole_update(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->published()->create();

        try {
            $this->upsert()->handle('Ropa de niño', 'ropa-nino', $this->actor, $category);
            $this->fail('El enlace bloqueado debería rechazar el guardado.');
        } catch (CategoryInUseException $exception) {
            $this->assertSame('slug', $exception->field);
        }

        $category->refresh();

        $this->assertSame('Ropa', $category->name);
        $this->assertSame('ropa', $category->slug);
    }

    public function test_a_scheduled_product_also_locks_the_slug(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->scheduled()->create();

        $this->expectException(CategoryInUseException::class);

        $this->upsert()->handle('Ropa', 'ropa-nino', $this->actor, $category);
    }

    public function test_deleting_keeps_the_positions_contiguous(): void
    {
        $first = $this->upsert()->handle('A', null, $this->actor);
        $second = $this->upsert()->handle('B', null, $this->actor);
        $third = $this->upsert()->handle('C', null, $this->actor);

        $this->upsert()->delete($second, $this->actor);

        $this->assertSame(1, $first->refresh()->position);
        $this->assertSame(2, $third->refresh()->position);
    }

    public function test_a_category_with_a_trashed_product_still_cannot_be_deleted(): void
    {
        $category = $this->upsert()->handle('Ropa', null, $this->actor);

        Product::factory()->inCategory($category)->create()->delete();

        $this->expectException(CategoryInUseException::class);

        $this->upsert()->delete($category, $this->actor);
    }

    public function test_a_member_may_manage_the_taxonomy(): void
    {
        $member = User::factory()->create();

        $this->assertSame(OrganizationRole::Member, $member->role);
        $this->assertSame('Del empleado', $this->upsert()->handle('Del empleado', null, $member)->name);
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->upsert()->handle('Ajena', null, $foreignOwner);
    }

    private function upsert(): UpsertCategoryAction
    {
        return app(UpsertCategoryAction::class);
    }
}
