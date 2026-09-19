<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\TaxonomyByName;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La creación al vuelo de `REQ-BOF-023` RB-8 y SP-6.
 *
 * Su llamante llega con `T-027` —el formulario de producto—, así que lo que
 * aquí se fija es el contrato con el que esa tarea va a contar: pedir una
 * categoría por nombre devuelve la que hay o crea una, **nunca falla por
 * duplicado**, y aguanta que dos peticiones pidan la misma a la vez.
 *
 * Clase de PHPUnit y no sintaxis de Pest: necesita los helpers de instancia
 * del caso base para situarse en una organización.
 */
final class TaxonomyByNameTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    public function test_it_creates_the_category_when_the_name_is_new(): void
    {
        $category = $this->taxonomy()->category('Herramientas');

        $this->assertSame('Herramientas', $category->name);
        $this->assertSame('herramientas', $category->slug);
        $this->assertSame(1, Category::query()->count());
    }

    /**
     * RB-8 · **si el nombre ya existe, se reutiliza**. Es la diferencia con el
     * CRUD, donde el mismo nombre es `422` (SP-2): allí la intención es «crea
     * una nueva» y aquí «pon el producto en esta».
     */
    public function test_it_reuses_the_existing_category(): void
    {
        $existing = Category::factory()->named('Ropa')->create();

        $reused = $this->taxonomy()->category('Ropa');

        $this->assertTrue($reused->is($existing));
        $this->assertSame(1, Category::query()->count());
    }

    /**
     * «Ropa» y «ropa» son la misma: lo hace la colación, que es la misma que
     * usa el índice único. Comparar en PHP diría que no y dejaría pasar el
     * duplicado hasta la excepción de la base.
     */
    public function test_it_reuses_ignoring_capitalisation(): void
    {
        $existing = Category::factory()->named('Ropa')->create();

        $this->assertTrue($this->taxonomy()->category('ROPA')->is($existing));
        $this->assertSame(1, Category::query()->count());
    }

    /**
     * Los espacios sobrantes tampoco crean una categoría nueva: «Ropa  niño» y
     * «Ropa niño» se ven idénticas en la lista, y dos entradas iguales a la
     * vista son un error de datos, no una decisión del negocio.
     */
    public function test_it_collapses_the_whitespace_of_the_name(): void
    {
        $existing = Category::factory()->named('Ropa de niño')->create();

        $this->assertTrue($this->taxonomy()->category('  Ropa  de   niño ')->is($existing));
    }

    /**
     * Dos nombres distintos con el mismo slug: el segundo se desambigua con
     * sufijo en lugar de fallar.
     */
    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        Category::factory()->named('Ropa niño')->create();

        $second = $this->taxonomy()->category('Ropa-niño');

        $this->assertSame('ropa-nino-2', $second->slug);
        $this->assertSame(2, Category::query()->count());
    }

    /**
     * **La carrera de §5.1, provocada a propósito.**
     *
     * El listener se cuela entre la comprobación y el `INSERT` —que es
     * exactamente la ventana que deja cualquier `firstOrCreate`— e inserta la
     * fila que gana. Nuestro `INSERT` choca contra
     * `UNIQUE(organization_id, name)`, y la respuesta correcta **no** es
     * propagar el error: quien lo provocó pidió «esta categoría», y la
     * categoría existe. Se relee y se devuelve la del ganador.
     *
     * Sin el índice de `T-024` este test pasaría con dos categorías en la
     * base, que es el daño que se estaba evitando.
     */
    public function test_a_simultaneous_request_for_the_same_name_reuses_the_winner(): void
    {
        $intercepted = false;

        Category::creating(function () use (&$intercepted): void {
            if ($intercepted) {
                return;
            }

            $intercepted = true;

            // La otra petición, escrita sin pasar por Eloquent para no
            // reentrar en este mismo evento.
            DB::table('categories')->insert([
                'organization_id' => $this->organization->getKey(),
                'name' => 'Ropa',
                'slug' => 'ropa',
                'position' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $category = $this->taxonomy()->category('Ropa');

        $this->assertTrue($intercepted);
        $this->assertSame('Ropa', $category->name);
        $this->assertSame(1, Category::query()->count());
    }

    public function test_it_reuses_an_existing_tag_and_creates_the_missing_one(): void
    {
        $existing = Tag::factory()->named('Oferta')->create();

        $this->assertTrue($this->taxonomy()->tag('Oferta')->is($existing));

        $created = $this->taxonomy()->tag('Novedad');

        $this->assertSame('novedad', $created->slug);
        $this->assertSame(2, Tag::query()->count());
    }

    /**
     * RB-1 · la taxonomía es de cada organización: la categoría del vecino no
     * se reutiliza, porque desde aquí **no existe**.
     */
    public function test_it_does_not_reuse_the_category_of_another_organization(): void
    {
        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): Category => Category::factory()->named('Ropa')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $own = $this->taxonomy()->category('Ropa');

        $this->assertFalse($own->is($foreign));
        $this->assertSame((int) $this->organization->getKey(), $own->organization_id);
    }

    /**
     * Crear al vuelo deja el orden manual sano: la nueva va al final y las
     * posiciones siguen siendo contiguas.
     */
    public function test_creating_inline_keeps_the_order_contiguous(): void
    {
        Category::factory()->named('Primera')->create(['position' => 7]);

        $created = $this->taxonomy()->category('Segunda');

        $this->assertSame([1, 2], Category::query()
            ->ordered()
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all());

        $this->assertSame(2, $created->position);
    }

    private function taxonomy(): TaxonomyByName
    {
        return app(TaxonomyByName::class);
    }
}
