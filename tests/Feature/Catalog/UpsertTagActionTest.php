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

/**
 * El alta, la edición y la baja de una etiqueta (`REQ-BOF-023` RB-5, RB-6).
 *
 * Lo que este archivo tiene que demostrar es **la asimetría con las
 * categorías**: aquí borrar funciona siempre y el enlace no se bloquea nunca.
 * No es una inconsistencia —un producto tiene una categoría y quitársela lo
 * deja sin sitio; una etiqueta es una marca suelta— y la spec ya acepta que un
 * filtro de etiqueta compartido deje de funcionar (SP-4).
 */
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

    /**
     * RB-6 · renombrar no toca el enlace, igual que en las categorías.
     */
    public function test_renaming_keeps_the_slug(): void
    {
        $tag = $this->upsert()->handle('Ofrta', null, $this->actor);

        $this->upsert()->handle('Oferta', null, $this->actor, $tag);

        $this->assertSame('Oferta', $tag->refresh()->name);
        $this->assertSame('ofrta', $tag->slug);
    }

    /**
     * **Y aquí sí se cambia con productos publicados**, que es la diferencia
     * con SP-3. `REQ-BOF-023` cierra el enlace de una *categoría*, no el de
     * una etiqueta, y es coherente con SP-4: si borrar una etiqueta usada está
     * permitido aunque rompa un filtro compartido, protegerla del renombrado
     * sería cuidar una puerta con la de al lado abierta.
     */
    public function test_the_slug_changes_even_with_published_products(): void
    {
        $tag = $this->upsert()->handle('Oferta', null, $this->actor);

        Product::factory()->published()->create()->tags()->attach($tag);

        $this->upsert()->handle('Oferta', 'ofertas', $this->actor, $tag);

        $this->assertSame('ofertas', $tag->refresh()->slug);
    }

    /**
     * RB-5 · borrar una etiqueta usada funciona y la desasocia; ningún
     * producto se pierde.
     */
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
