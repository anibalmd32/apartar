<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\UpsertTagAction;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UpsertTagActionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_creates_the_tag_with_a_derived_slug(): void
    {
        $tag = $this->upsert()->handle('Última unidad', null, $this->actor);

        $this->assertSame('Última unidad', $tag->name);
        $this->assertSame('ultima-unidad', $tag->slug);
    }

    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        $this->upsert()->handle('Ropa niño', null, $this->actor);

        $this->assertSame('ropa-nino-2', $this->upsert()->handle('Ropa-niño', null, $this->actor)->slug);
    }

    public function test_renaming_keeps_the_slug(): void
    {
        $tag = $this->upsert()->handle('Ofrta', null, $this->actor);

        $this->upsert()->handle('Oferta', null, $this->actor, $tag);

        $this->assertSame('Oferta', $tag->refresh()->name);
        $this->assertSame('ofrta', $tag->slug);
    }

    public function test_the_slug_changes_even_with_published_products(): void
    {
        $tag = $this->upsert()->handle('Oferta', null, $this->actor);

        Product::factory()->published()->create()->tags()->attach($tag);

        $this->upsert()->handle('Oferta', 'ofertas', $this->actor, $tag);

        $this->assertSame('ofertas', $tag->refresh()->slug);
    }

    public function test_deleting_a_used_tag_detaches_it_without_touching_the_products(): void
    {
        $tag = $this->upsert()->handle('Oferta', null, $this->actor);

        Product::factory()->count(2)->create()->each(fn (Product $product) => $product->tags()->attach($tag));

        $this->upsert()->delete($tag, $this->actor);

        $this->assertNull(Tag::query()->find($tag->getKey()));
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(0, DB::table('product_tag')->where('tag_id', $tag->getKey())->count());
    }

    public function test_a_member_may_manage_the_tags(): void
    {
        $member = User::factory()->create();

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

    private function upsert(): UpsertTagAction
    {
        return app(UpsertTagAction::class);
    }
}
