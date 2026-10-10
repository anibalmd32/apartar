<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ProductData;
use App\Actions\Product\SpecificationInput;
use App\Actions\Product\SyncSpecificationsAction;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Money\Money;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * El reemplazo completo de la ficha técnica (`REQ-BOF-021`).
 *
 * Dos mitades: lo que hace la acción por su cuenta —reemplazar, renumerar,
 * rechazar el exceso— y lo que solo se ve **desde su llamante**, que es que la
 * ficha y el producto se guardan o se descartan juntos (RB-8, SP-4). La
 * segunda es la que de verdad importa y la que no se podría probar con la
 * acción aislada.
 */
final class SyncSpecificationsActionTest extends TestCase
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
        // `T-067` · crear, publicar y componer preguntan al plan.
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    /**
     * RB-4 · las posiciones salen del **orden de la lista** y quedan contiguas
     * desde 1, sea cual sea el número que traían las filas.
     */
    public function test_it_writes_contiguous_positions_from_one(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            SpecificationInput::of('Motor', 'Potencia', '750 W'),
            SpecificationInput::of('Motor', 'Velocidades', '2'),
            SpecificationInput::of(null, 'Material', 'Acero'),
        ]);

        expect($this->rowsOf($product))->toBe([
            ['position' => 1, 'group' => 'Motor', 'label' => 'Potencia'],
            ['position' => 2, 'group' => 'Motor', 'label' => 'Velocidades'],
            ['position' => 3, 'group' => null, 'label' => 'Material'],
        ]);
    }

    /**
     * Y **reordenar no conserva el número que traía la fila**: la lista nueva
     * se renumera entera. Es lo que hace que el orden del arrastre sea el
     * orden, sin que el formulario tenga que mandar posiciones.
     */
    public function test_reordering_renumbers_the_whole_sheet(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            SpecificationInput::of(null, 'Primera', 'A'),
            SpecificationInput::of(null, 'Segunda', 'B'),
            SpecificationInput::of(null, 'Tercera', 'C'),
        ]);

        $this->sync()->handle($product, [
            SpecificationInput::of(null, 'Tercera', 'C'),
            SpecificationInput::of(null, 'Primera', 'A'),
        ]);

        expect($this->rowsOf($product))->toBe([
            ['position' => 1, 'group' => null, 'label' => 'Tercera'],
            ['position' => 2, 'group' => null, 'label' => 'Primera'],
        ]);
    }

    /**
     * RB-5 · borra y reinserta: las filas de después **no son** las de antes,
     * ni siquiera las que no cambiaron. Es la consecuencia del reemplazo, y
     * por eso el `id` de una fila no es una promesa que la pantalla pueda
     * guardar.
     */
    public function test_the_replacement_reinserts_every_row(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [SpecificationInput::of(null, 'Material', 'Acero')]);

        $before = ProductSpecification::query()->sole()->getKey();

        $this->sync()->handle($product, [SpecificationInput::of(null, 'Material', 'Acero')]);

        expect(ProductSpecification::query()->count())->toBe(1);
        expect(ProductSpecification::query()->sole()->getKey())->not->toBe($before);
    }

    /**
     * SP-3 · **cero filas borra todas** y no falla. Tratarlo como «no hay nada
     * que hacer» haría que quitar la última fila no la quitara.
     */
    public function test_an_empty_list_deletes_every_row(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            SpecificationInput::of(null, 'Material', 'Acero'),
            SpecificationInput::of(null, 'Peso', '2 kg'),
        ]);

        $this->sync()->handle($product, []);

        expect(ProductSpecification::query()->count())->toBe(0);
    }

    /**
     * SP-7 · gana el último, sin *merge*: la lista nueva sustituye a la vieja
     * entera, no se negocia fila por fila.
     */
    public function test_the_last_list_wins_without_merging(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            SpecificationInput::of(null, 'Material', 'Acero'),
            SpecificationInput::of(null, 'Peso', '2 kg'),
        ]);

        $this->sync()->handle($product, [SpecificationInput::of(null, 'Color', 'Azul')]);

        expect(array_column($this->rowsOf($product), 'label'))->toBe(['Color']);
    }

    /**
     * RB-3 y SP-2 · **51 filas se rechazan**, 50 pasan. El tope es técnico: no
     * pasa por `LimitEnforcer` y nadie desbloquea la fila 51 pagando más.
     */
    public function test_it_accepts_fifty_rows_and_rejects_fifty_one(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS));

        expect(ProductSpecification::query()->count())->toBe(SyncSpecificationsAction::MAX_ROWS);

        try {
            $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS + 1));
            $this->fail('Cincuenta y una filas deberían rechazarse.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('specifications');
        }
    }

    /**
     * Y el rechazo ocurre **antes de borrar**: una ficha de 50 filas sigue ahí
     * después de que alguien intente guardar 51.
     */
    public function test_the_row_limit_is_checked_before_deleting(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(3));

        try {
            $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS + 1));
        } catch (ValidationException) {
            // Se espera.
        }

        expect(ProductSpecification::query()->count())->toBe(3);
    }

    /**
     * **SP-4, que es la razón de que la ficha no tenga endpoint propio.**
     *
     * Un fallo a mitad del guardado deja las especificaciones anteriores
     * íntegras. Se provoca desde el llamante real —`UpsertProductAction`— con
     * una etiqueta de otra organización, que es un rechazo que ocurre
     * **después** de que la ficha se haya reemplazado dentro de la
     * transacción: si el reemplazo no estuviera dentro, las filas viejas ya
     * estarían borradas y las nuevas escritas.
     */
    public function test_a_failure_halfway_keeps_the_previous_sheet_intact(): void
    {
        $product = Product::factory()->create(['sku' => 'BOSCH-01']);

        $this->sync()->handle($product, [
            SpecificationInput::of('Motor', 'Potencia', '750 W'),
            SpecificationInput::of(null, 'Material', 'Acero'),
        ]);

        $foreignTag = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            app(UpsertProductAction::class)->handle(
                $this->productData([SpecificationInput::of(null, 'Color', 'Azul')], [(int) $foreignTag->getKey()]),
                $this->actor,
                $product,
            );
            $this->fail('La etiqueta ajena debería rechazar el guardado.');
        } catch (CrossTenantRelationException) {
            // Se espera.
        }

        expect(array_column($this->rowsOf($product), 'label'))->toBe(['Potencia', 'Material']);
    }

    /**
     * RB-8 · la ficha se guarda **con el producto**, en la misma operación.
     */
    public function test_the_sheet_is_saved_with_the_product(): void
    {
        $product = app(UpsertProductAction::class)->handle(
            $this->productData([
                SpecificationInput::of('Motor', 'Potencia', '750 W'),
                SpecificationInput::of(null, 'Material', 'Acero'),
            ]),
            $this->actor,
        );

        expect(array_column($this->rowsOf($product), 'label'))->toBe(['Potencia', 'Material']);
    }

    /**
     * Y **`null` no la toca**, que no es lo mismo que la lista vacía: un
     * llamante que no gestiona la ficha —un seeder, una importación— no puede
     * borrarla sin pedirlo.
     */
    public function test_a_null_sheet_leaves_the_rows_alone(): void
    {
        $product = app(UpsertProductAction::class)->handle(
            $this->productData([SpecificationInput::of(null, 'Material', 'Acero')]),
            $this->actor,
        );

        app(UpsertProductAction::class)->handle($this->productData(null), $this->actor, $product);

        expect(ProductSpecification::query()->count())->toBe(1);
    }

    /**
     * La tabla es del tenant (`REQ-CAT-004` RB-6): cada fila nace con su
     * `organization_id` puesto por el trait, también cuando se escriben
     * cincuenta de una vez.
     */
    public function test_every_row_belongs_to_the_active_organization(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(4));

        expect(ProductSpecification::query()->pluck('organization_id')->unique()->all())
            ->toBe([(int) $this->organization->getKey()]);
    }

    /**
     * El contenido se guarda **tal cual**: el escapado es de quien renderiza
     * (`REQ-CAT-004` RB-4), y sanear aquí dejaría `&lt; 2 kg` en la base.
     */
    public function test_it_persists_the_value_verbatim(): void
    {
        $product = Product::factory()->create();
        $attack = '<script>alert("xss")</script>';

        $this->sync()->handle($product, [SpecificationInput::of(null, 'Nota', $attack)]);

        expect(ProductSpecification::query()->sole()->value)->toBe($attack);
    }

    /**
     * Las filas del producto, en su orden de guardado.
     *
     * @return list<array{position: int, group: string|null, label: string}>
     */
    private function rowsOf(Product $product): array
    {
        return ProductSpecification::query()
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSpecification $row): array => [
                'position' => $row->position,
                'group' => $row->group,
                'label' => $row->label,
            ])
            ->all();
    }

    /**
     * @return list<SpecificationInput>
     */
    private function rows(int $count): array
    {
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = SpecificationInput::of(null, 'Característica '.$number, 'Valor '.$number);
        }

        return $rows;
    }

    /**
     * @param  list<SpecificationInput>|null  $specifications
     * @param  list<int>  $tagIds
     */
    private function productData(?array $specifications, array $tagIds = []): ProductData
    {
        return new ProductData(
            name: 'Taladro Bosch',
            sku: 'BOSCH-01',
            slug: null,
            description: 'Un taladro.',
            price: Money::fromDecimalString('250.00'),
            compareAtPrice: null,
            downPayment: Money::fromDecimalString('50.00'),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: 10,
            tagIds: $tagIds,
            specifications: $specifications,
        );
    }

    private function sync(): SyncSpecificationsAction
    {
        return app(SyncSpecificationsAction::class);
    }
}
