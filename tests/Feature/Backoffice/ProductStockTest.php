<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * Las existencias en el backoffice cuando hay apartados (`REQ-LAY-003` RB-6,
 * SP-2; `REQ-BOF-020` SP-6).
 *
 * El caso que decide el reparto es el de SP-2 —el negocio declara **una**
 * unidad en la tienda y tiene **tres** apartadas—, y las tres cifras que salen
 * de ahí son distintas y cada una va a su sitio:
 *
 *  - la columna `stock` guarda **0**: no queda ninguna para apartar, y nunca
 *    es negativa (RB-4);
 *  - la pantalla enseña **1** en «Existencias», que es lo que el negocio
 *    escribió y lo que vuelve a enviar, con «3 en apartados vigentes · ninguna
 *    disponible» debajo;
 *  - la alerta dice que **faltan 2**, y `stock_shortfall` las guarda para que
 *    cancelar uno de esos apartados no ponga a la venta una unidad que no hay.
 */
final class ProductStockTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * RB-6 · «Existencias» son las de la tienda —disponibles más apartadas—, y
     * el reparto va debajo.
     */
    public function test_the_form_shows_the_units_on_hand_and_their_breakdown(): void
    {
        $product = $this->productWithReservations(stock: 5, reservations: [2]);

        $this->actingAs($this->owner)
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.stock', 5)
                ->where('product.stockBreakdown.available', 3)
                ->where('product.stockBreakdown.reserved', 2)
                ->where('product.stockBreakdown.shortfall', 0));
    }

    /**
     * SP-2 · se permite declarar menos de lo apartado: la columna queda en
     * cero, el faltante se guarda, y **ningún apartado se cancela**.
     */
    public function test_declaring_fewer_units_than_reserved_is_allowed_and_leaves_the_shortfall(): void
    {
        $product = $this->productWithReservations(stock: 5, reservations: [1, 1, 1]);

        $this->save($product, onHand: 1)->assertSessionHasNoErrors();

        $row = DB::table('products')->where('id', $product->getKey())->first(['stock', 'stock_shortfall']);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->stock);
        $this->assertSame(2, (int) $row->stock_shortfall);

        $this->assertSame(3, DB::table('layaways')->where('product_id', $product->getKey())->where('status', LayawayStatus::PendingDownPayment->value)->count());

        $this->actingAs($this->owner)
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.stock', 1)
                ->where('product.stockBreakdown.available', 0)
                ->where('product.stockBreakdown.reserved', 3)
                ->where('product.stockBreakdown.shortfall', 2));

        $this->actingAs($this->owner)
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.stock', 0)
                ->where('rows.0.reservedUnits', 3)
                ->where('rows.0.shortfallUnits', 2));
    }

    /**
     * Guardar el formulario sin tocar nada no mueve ninguna cifra: lo que se
     * enseña es exactamente lo que, reenviado, reproduce lo guardado.
     */
    public function test_saving_the_form_untouched_keeps_every_figure(): void
    {
        $product = $this->productWithReservations(stock: 5, reservations: [1, 1, 1]);
        $this->save($product, onHand: 1);

        $this->save($product, onHand: 1);

        $row = DB::table('products')->where('id', $product->getKey())->first(['stock', 'stock_shortfall']);
        $this->assertNotNull($row);
        $this->assertSame([0, 2], [(int) $row->stock, (int) $row->stock_shortfall]);

        // Y con unidades de sobra, lo disponible es lo declarado menos lo apartado.
        $this->save($product, onHand: 8);

        $row = DB::table('products')->where('id', $product->getKey())->first(['stock', 'stock_shortfall']);
        $this->assertNotNull($row);
        $this->assertSame([5, 0], [(int) $row->stock, (int) $row->stock_shortfall]);
    }

    /**
     * Un apartado creado entre la carga del formulario y su envío **no
     * resucita** su unidad: las existencias no cambiaron, las apartadas sí, y
     * el reparto se hace con las de ahora.
     */
    public function test_a_reservation_made_while_the_form_was_open_is_not_undone(): void
    {
        $product = $this->productWithReservations(stock: 5, reservations: []);

        // El formulario se abrió con 5 en la tienda; mientras tanto, un cliente aparta 1.
        $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product);
        $this->withoutOrganization();

        $this->save($product, onHand: 5);

        $this->assertSame(4, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    /**
     * El listado trae las reservadas **en su misma consulta**: sembrar
     * apartados no añade ninguna, ni por página ni por fila.
     */
    public function test_the_listing_brings_the_reserved_units_without_a_query_per_row(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->productWithReservations(stock: 5, reservations: []);
        }

        $without = $this->queriesFor('/backoffice/productos');

        for ($n = 0; $n < 6; $n++) {
            $this->productWithReservations(stock: 5, reservations: [1, 2]);
        }

        $with = $this->queriesFor('/backoffice/productos');

        $this->assertSame($without, $with);
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<int>  $reservations  la cantidad de cada apartado vigente
     */
    private function productWithReservations(int $stock, array $reservations): Product
    {
        $product = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['stock' => $stock]));

        foreach ($reservations as $quantity) {
            $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product, $quantity);
        }

        $this->withoutOrganization();

        return $product;
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function save(Product $product, int $onHand): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->put("/backoffice/productos/{$product->getKey()}", [
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => 'Una nevera.',
            'price' => '250.00',
            'compare_at_price' => '',
            'down_payment' => '50.00',
            'installments_count' => 4,
            'frequency_days' => 15,
            'stock' => $onHand,
        ]);
    }

    private function queriesFor(string $url): int
    {
        $this->actingAs($this->owner)->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
