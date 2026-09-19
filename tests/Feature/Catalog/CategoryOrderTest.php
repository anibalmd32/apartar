<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CategoryOrder;
use App\Models\Category;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El orden manual de `REQ-BOF-023` RB-2 y SP-5.
 *
 * Lo que se fija aquí es la invariante que no se ve en la pantalla:
 * **`position` queda `1..n` sin huecos**. Un hueco se pinta igual —la lista
 * sale ordenada de todas formas— y aparece un rato después, cuando el
 * siguiente arrastre calcula sobre una numeración que no coincide con lo que
 * la persona tiene delante.
 */
final class CategoryOrderTest extends TestCase
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

    public function test_it_applies_the_requested_order_from_one(): void
    {
        $ids = $this->seedNamed('A', 'B', 'C');

        $this->order()->apply([$ids['C'], $ids['A'], $ids['B']]);

        $this->assertSame(['C', 'A', 'B'], $this->names());
        $this->assertSame([1, 2, 3], $this->positions());
    }

    /**
     * §5.5 · llamarlo sin lista **compacta** lo que haya, conservando el orden
     * relativo. Es lo que usan el alta y la baja para que borrar la tercera de
     * cinco no deje 1, 2, 4, 5.
     */
    public function test_calling_it_empty_compacts_the_existing_order(): void
    {
        $this->seedNamed('A', 'B', 'C');

        Category::query()->where('name', 'A')->update(['position' => 3]);
        Category::query()->where('name', 'B')->update(['position' => 9]);
        Category::query()->where('name', 'C')->update(['position' => 40]);

        $this->order()->apply();

        $this->assertSame(['A', 'B', 'C'], $this->names());
        $this->assertSame([1, 2, 3], $this->positions());
    }

    /**
     * Lo que no viene en la lista **no se pierde**: se va al final. Entre que
     * la pantalla carga y alguien suelta el arrastre, otra persona pudo crear
     * una categoría, y dejarla sin posición la sacaría del orden sin que nadie
     * lo hubiera pedido.
     */
    public function test_what_is_missing_from_the_list_goes_last(): void
    {
        $ids = $this->seedNamed('A', 'B', 'C');

        $this->order()->apply([$ids['C']]);

        $this->assertSame(['C', 'A', 'B'], $this->names());
        $this->assertSame([1, 2, 3], $this->positions());
    }

    /**
     * Un identificador repetido es un arrastre mal serializado, no una
     * intención: gana su primera aparición y no ocupa dos sitios.
     */
    public function test_a_repeated_identifier_only_counts_once(): void
    {
        $ids = $this->seedNamed('A', 'B');

        $this->order()->apply([$ids['B'], $ids['B'], $ids['A']]);

        $this->assertSame(['B', 'A'], $this->names());
        $this->assertSame([1, 2], $this->positions());
    }

    /**
     * **La fuga que este archivo tiene que cerrar.** Un identificador de otra
     * organización no ordena nada ni se cuela en la lista: la numeración parte
     * de `Category::query()`, con el global scope puesto, así que esa fila no
     * existe aquí.
     */
    public function test_an_identifier_of_another_organization_is_ignored(): void
    {
        $ids = $this->seedNamed('A', 'B');

        $foreign = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Category => Category::factory()->named('Ajena')->create(['position' => 1]),
        );

        $this->actingAsOrganization($this->organization);

        $this->order()->apply([(int) $foreign->getKey(), $ids['B'], $ids['A']]);

        $this->assertSame(['B', 'A'], $this->names());
        $this->assertSame([1, 2], $this->positions());

        // Y la del vecino se queda como estaba.
        $this->assertSame(1, $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): int => (int) $foreign->refresh()->position,
        ));
    }

    /**
     * @return array<string, int>
     */
    private function seedNamed(string ...$names): array
    {
        $ids = [];
        $position = 1;

        foreach ($names as $name) {
            $ids[$name] = (int) Category::factory()->named($name)->create(['position' => $position])->getKey();
            $position++;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return Category::query()->ordered()->pluck('name')->all();
    }

    /**
     * @return list<int>
     */
    private function positions(): array
    {
        return Category::query()
            ->ordered()
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all();
    }

    private function order(): CategoryOrder
    {
        return app(CategoryOrder::class);
    }
}
