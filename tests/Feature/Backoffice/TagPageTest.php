<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Las etiquetas por HTTP (`REQ-BOF-023` RB-5, SP-4).
 *
 * Más corto que el de categorías, y esa diferencia **es** el requisito: lo que
 * hay que demostrar aquí es que borrar una etiqueta usada **funciona** y deja
 * a sus productos intactos, que es exactamente lo contrario de lo que pasa con
 * una categoría.
 */
final class TagPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-7 · la lista llega por nombre y con el conteo de cada una.
     */
    public function test_the_page_carries_the_tags_by_name_with_their_count(): void
    {
        $this->inTenant(function (): void {
            $oferta = Tag::factory()->named('Oferta')->create();
            Tag::factory()->named('Novedad')->create();

            Product::factory()->create()->tags()->attach($oferta);
        });

        $this->actingAs($this->owner())
            ->get('/backoffice/etiquetas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/taxonomy/Tags')
                ->has('tags', 2)
                ->where('tags.0.name', 'Novedad')
                ->where('tags.0.productsCount', 0)
                ->where('tags.1.name', 'Oferta')
                ->where('tags.1.productsCount', 1));
    }

    public function test_creating_a_tag_derives_its_slug(): void
    {
        $this->actingAs($this->owner())
            ->post('/backoffice/etiquetas', ['name' => 'Última unidad'])
            ->assertRedirect('/backoffice/etiquetas');

        $tag = $this->inTenant(fn (): Tag => Tag::query()->sole());

        $this->assertSame('Última unidad', $tag->name);
        $this->assertSame('ultima-unidad', $tag->slug);
    }

    /**
     * SP-2 · el nombre duplicado también es `422` en las etiquetas.
     */
    public function test_a_duplicate_tag_name_is_rejected(): void
    {
        $this->inTenant(fn () => Tag::factory()->named('Oferta')->create());

        $this->actingAs($this->owner())
            ->post('/backoffice/etiquetas', ['name' => 'Oferta'])
            ->assertSessionHasErrors('name');
    }

    /**
     * RB-6 · renombrar tampoco toca el slug aquí.
     */
    public function test_renaming_a_tag_does_not_change_its_slug(): void
    {
        $tag = $this->inTenant(fn (): Tag => Tag::factory()->named('Ofrta')->create());

        $this->actingAs($this->owner())
            ->put("/backoffice/etiquetas/{$tag->getKey()}", ['name' => 'Oferta'])
            ->assertRedirect('/backoffice/etiquetas');

        $tag->refresh();

        $this->assertSame('Oferta', $tag->name);
        $this->assertSame('ofrta', $tag->slug);
    }

    /**
     * **RB-5 y SP-4, que son la asimetría del requisito.**
     *
     * Borrar una etiqueta usada funciona, la desasocia de sus productos y no
     * borra ninguno. El filtro que alguien tuviera en un enlace compartido se
     * ignora al abrirlo (`REQ-CAT-006` SP-4); no hay nada que bloquear.
     */
    public function test_deleting_a_used_tag_detaches_it_and_keeps_the_products(): void
    {
        $tag = $this->inTenant(function (): Tag {
            $tag = Tag::factory()->named('Oferta')->create();

            Product::factory()->count(2)->create()->each(fn (Product $product) => $product->tags()->attach($tag));

            return $tag;
        });

        $this->actingAs($this->owner())
            ->delete("/backoffice/etiquetas/{$tag->getKey()}")
            ->assertSessionHasNoErrors();

        $this->assertNull($this->inTenant(fn (): ?Tag => Tag::query()->find($tag->getKey())));
        $this->assertSame(2, $this->inTenant(fn (): int => Product::query()->count()));
        $this->assertSame(0, DB::table('product_tag')->where('tag_id', $tag->getKey())->count());
    }

    /**
     * La etiqueta de otra organización da `404`, no `403`
     * (`REQ-ORG-002` RB-6).
     */
    public function test_a_tag_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Ajena')->create(),
        );

        $this->actingAs($this->owner())
            ->delete("/backoffice/etiquetas/{$foreign->getKey()}")
            ->assertNotFound();
    }

    /**
     * De los dos roles, igual que las categorías.
     */
    public function test_a_member_manages_the_tags(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/etiquetas')->assertOk();
        $this->actingAs($member)->post('/backoffice/etiquetas', ['name' => 'Del empleado'])->assertSessionHasNoErrors();
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * Siembra y lee dentro de la organización del escenario.
     *
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
