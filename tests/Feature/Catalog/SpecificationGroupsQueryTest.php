<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Queries\SpecificationGroupsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El autocompletado de secciones (`REQ-BOF-021` RB-6, SP-8).
 *
 * Es una fuga esperando a ocurrir: un autocompletado que sugiriera «Motor»
 * porque lo usa la ferretería de al lado no daría un error, daría una pista de
 * que ese negocio existe y de cómo describe lo que vende. Por eso el caso de
 * dos organizaciones no es opcional.
 */
final class SpecificationGroupsQueryTest extends TestCase
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

    public function test_it_offers_nothing_when_the_business_has_no_sheets(): void
    {
        $this->assertSame([], $this->query()->all());
    }

    /**
     * RB-6 · las secciones ya usadas, sin repetir y en orden alfabético.
     */
    public function test_it_offers_the_groups_already_used_in_alphabetical_order(): void
    {
        $this->sheetWith(['Motor', 'Acabado', 'Motor']);

        $this->assertSame(['Acabado', 'Motor'], $this->query()->all());
    }

    /**
     * Las filas sin sección no aportan nada que sugerir: «General» es un
     * rótulo de la pantalla, no una sección que el negocio haya escrito.
     */
    public function test_it_ignores_the_rows_without_a_group(): void
    {
        $this->sheetWith(['Motor', null, null]);

        $this->assertSame(['Motor'], $this->query()->all());
    }

    /**
     * Deduplica como agrupa la ficha (`REQ-CAT-004` SP-2): sugerir «Motor» y
     * «motor» como dos opciones invitaría a crear justo el duplicado que la
     * ficha luego junta.
     */
    public function test_it_deduplicates_ignoring_capitalisation(): void
    {
        $this->sheetWith(['Motor', 'motor', 'MOTOR']);

        $this->assertCount(1, $this->query()->all());
    }

    /**
     * Sugiere también las del producto que se está editando, y es deliberado:
     * quien acaba de escribir «Motor» en tres filas y añade la cuarta espera
     * que se le ofrezca. La frontera que importa es la de la organización.
     */
    public function test_it_offers_the_groups_of_every_product_including_the_current_one(): void
    {
        $first = Product::factory()->create(['sku' => 'A-1', 'slug' => 'a-1']);
        $second = Product::factory()->create(['sku' => 'A-2', 'slug' => 'a-2']);

        $this->rowIn($first, 'Motor');
        $this->rowIn($second, 'Acabado');

        $this->assertSame(['Acabado', 'Motor'], $this->query()->all());
    }

    /**
     * **SP-8 · el test de fuga.** Las secciones del negocio de al lado no se
     * sugieren nunca, y lo que lo impide es el *global scope* y no un `where`
     * escrito a mano.
     */
    public function test_it_never_offers_the_groups_of_another_organization(): void
    {
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($neighbour, function (): void {
            $this->sheetWith(['Del vecino']);
        });

        $this->actingAsOrganization($this->organization);
        $this->sheetWith(['Motor']);

        $this->assertSame(['Motor'], $this->query()->all());

        // Y la comprobación en el otro sentido, para que el caso no pase por
        // estar ambas vacías: el vecino tampoco ve las nuestras.
        $this->assertSame(
            ['Del vecino'],
            $this->withinOrganization($neighbour, fn (): array => $this->query()->all()),
        );
    }

    /**
     * Siembra un producto con una fila por cada sección de la lista.
     *
     * @param  list<string|null>  $groups
     */
    private function sheetWith(array $groups): void
    {
        $product = Product::factory()->create();

        foreach ($groups as $group) {
            $this->rowIn($product, $group);
        }
    }

    private function rowIn(Product $product, ?string $group): void
    {
        ProductSpecification::factory()->forProduct($product)->create([
            'group' => $group,
            'label' => 'Característica',
            'value' => 'Valor',
        ]);
    }

    private function query(): SpecificationGroupsQuery
    {
        return app(SpecificationGroupsQuery::class);
    }
}
