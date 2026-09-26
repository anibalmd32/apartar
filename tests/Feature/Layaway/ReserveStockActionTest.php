<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ReserveStockAction;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La reserva de stock (`REQ-LAY-003` RB-1, RB-4, RB-5, RB-6), contra la base
 * de datos.
 *
 * Lo que necesita dos conexiones —que el bloqueo serialice dos reservas de la
 * última unidad (SP-1)— está en `tests/Feature/Concurrency/ReserveStockConcurrencyTest`;
 * el orden de los bloqueos, en `StockLockOrderTest`; la liberación, en
 * `ReleaseStockActionTest`; y que no se alcance el producto de otro negocio,
 * en `tests/Feature/Tenancy/StockIsolationTest`.
 */
final class ReserveStockActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    // -------------------------------------------------------------------
    // Reservar
    // -------------------------------------------------------------------

    /**
     * RB-1 · se descuenta al crear el apartado, y reservar **no es editar** el
     * producto: su marca de última modificación no se mueve (`REQ-BOF-020`
     * SP-7).
     */
    public function test_reserving_takes_the_units_without_touching_the_last_edit(): void
    {
        $editor = User::factory()->owner()->create();
        $product = Product::factory()->create(['stock' => 3]);
        DB::table('products')->where('id', $product->getKey())->update(['updated_by' => $editor->getKey(), 'updated_at' => '2026-09-01 10:00:00']);

        $locked = app(ReserveStockAction::class)->handle($product, 2);

        $row = DB::table('products')->where('id', $product->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->stock);
        $this->assertSame(1, $locked->stock);
        $this->assertSame((int) $editor->getKey(), (int) $row->updated_by);
        $this->assertSame('2026-09-01 10:00:00', $row->updated_at);
    }

    /**
     * RB-4 · la última unidad se reserva, y la siguiente no: el stock llega a
     * cero y **nunca** baja de ahí.
     */
    public function test_the_stock_never_goes_below_zero(): void
    {
        $product = Product::factory()->create(['stock' => 1]);

        app(ReserveStockAction::class)->handle($product, 1);

        try {
            app(ReserveStockAction::class)->handle($product, 1);
            $this->fail('Se reservó una unidad que no había.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(0, $exception->available);
            $this->assertSame(1, $exception->requested);
            $this->assertSame(InsufficientStockException::STATUS, 422);
        }

        $this->assertSame(0, $this->stockOf($product));
    }

    /**
     * La comprobación va contra la fila bloqueada, no contra el modelo que
     * llega: uno leído antes de que otra reserva se llevara unidades no puede
     * reservar las que ya no están.
     */
    public function test_a_stale_model_cannot_reserve_units_that_are_gone(): void
    {
        $product = Product::factory()->create(['stock' => 2]);
        $stale = Product::query()->findOrFail($product->getKey());

        app(ReserveStockAction::class)->handle($product, 2);

        try {
            app(ReserveStockAction::class)->handle($stale, 1);
            $this->fail('El modelo viejo reservó una unidad que ya estaba apartada.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(0, $exception->available);
        }

        $this->assertSame(0, $this->stockOf($product));
    }

    /**
     * SP-1 del formulario · el mensaje dice cuántas quedan, para que el cliente
     * reduzca la cantidad.
     */
    public function test_the_refusal_says_how_many_units_are_left(): void
    {
        $product = Product::factory()->create(['stock' => 2]);

        try {
            app(ReserveStockAction::class)->handle($product, 3);
            $this->fail('Se reservaron tres unidades de dos.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(2, $exception->available);
            $this->assertStringContainsString('quedan 2 unidades', $exception->getMessage());
        }

        $this->assertSame(2, $this->stockOf($product));
    }

    /**
     * RB-5 · agotado no es despublicado: el producto sigue `published`.
     */
    public function test_reaching_zero_does_not_change_the_product_status(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'status' => ProductStatus::Published, 'published_at' => now()->subDay()]);

        app(ReserveStockAction::class)->handle($product, 1);

        $this->assertSame(ProductStatus::Published->value, DB::table('products')->where('id', $product->getKey())->value('status'));
    }

    /**
     * Un apartado inmoviliza al menos una unidad (`REQ-LAY-001` RB-5): cero o
     * menos no es una reserva, y una negativa sería una devolución disfrazada.
     */
    public function test_a_reservation_of_no_units_is_refused(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        foreach ([0, -2] as $quantity) {
            try {
                app(ReserveStockAction::class)->handle($product, $quantity);
                $this->fail("Se reservaron {$quantity} unidades.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(3, $this->stockOf($product));
    }

    // -------------------------------------------------------------------
    // Las reservadas (RB-6)
    // -------------------------------------------------------------------

    /**
     * RB-6 · las reservadas son la suma de `quantity` de los apartados **no
     * terminales**: ni los expirados, ni los cancelados, ni los completados.
     */
    public function test_reserved_units_count_only_layaways_that_still_hold_stock(): void
    {
        $product = Product::factory()->create(['stock' => 20]);

        $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product, quantity: 1);
        $this->layawayIn($this->organization, LayawayStatus::Active, $product, quantity: 2);
        $this->layawayIn($this->organization, LayawayStatus::Overdue, $product, quantity: 3);
        $this->layawayIn($this->organization, LayawayStatus::Completed, $product, quantity: 4);
        $this->layawayIn($this->organization, LayawayStatus::Cancelled, $product, quantity: 5);

        $fresh = Product::query()->findOrFail($product->getKey());
        $listed = Product::query()->withReservedUnits()->findOrFail($product->getKey());

        $this->assertSame(6, $fresh->reservedUnits());
        $this->assertSame(6, $listed->reservedUnits());
    }

    /**
     * El censo de los que retienen stock se deriva de `isTerminal()`, no se
     * escribe aparte.
     */
    public function test_the_statuses_holding_stock_are_the_non_terminal_ones(): void
    {
        foreach (LayawayStatus::cases() as $status) {
            $this->assertSame(! $status->isTerminal(), in_array($status, LayawayStatus::holdingStock(), true), $status->value);
        }
    }

    // -------------------------------------------------------------------

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->getKey())->value('stock');
    }
}
