<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ReleaseStockAction;
use App\Actions\Layaway\ReserveStockAction;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El bloqueo de stock contra la organización vecina (`REQ-LAY-003` RB-7,
 * `REQ-QAX-008` RB-2).
 *
 * Un `FOR UPDATE` es donde más apetece escribir un `DB::table()`, y es justo
 * donde no puede haberlo: con el tenant de un negocio fijado, el producto del
 * otro **no existe** — no se lee, no se bloquea y no se toca su stock.
 */
final class StockIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_product_of_another_organization_cannot_be_reserved(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $neighbours = $this->withinOrganization($theirs, fn (): Product => Product::factory()->create(['stock' => 4]));

        try {
            $this->withinOrganization($ours, fn () => app(ReserveStockAction::class)->handle($neighbours, 1));
            $this->fail('Se reservó el producto de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame(4, (int) DB::table('products')->where('id', $neighbours->getKey())->value('stock'));
    }

    public function test_the_stock_of_another_organization_cannot_be_released(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $product = $this->withinOrganization($theirs, fn (): Product => Product::factory()->create(['stock' => 4]));
        $layaway = $this->layawayIn($theirs, LayawayStatus::Cancelled, $product, quantity: 2);

        // La máquina ya devolvió las dos al cancelar; se borra la marca para
        // que un intento desde el otro negocio tuviera algo que liberar.
        DB::table('layaways')->where('id', $layaway->getKey())->update(['stock_released_at' => null]);

        try {
            $this->withinOrganization($ours, fn () => app(ReleaseStockAction::class)->handle($layaway));
            $this->fail('Se liberó stock de un apartado de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame(4, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('stock_released_at'));
    }

    /**
     * Las reservadas del listado son las del negocio fijado: la subconsulta
     * pasa por el global scope de `Layaway`.
     */
    public function test_reserved_units_never_add_up_layaways_of_another_organization(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $product = $this->withinOrganization($ours, fn (): Product => Product::factory()->create(['stock' => 10]));
        $this->layawayIn($ours, LayawayStatus::Active, $product, quantity: 2);

        // Un apartado de la vecina apuntando a nuestro producto no puede
        // existir por la aplicación; se fuerza para ver que no se suma.
        $foreign = $this->layawayIn($theirs, LayawayStatus::Active, quantity: 3);
        DB::table('layaways')->where('id', $foreign->getKey())->update(['product_id' => $product->getKey()]);

        $reserved = $this->withinOrganization($ours, fn (): int => Product::query()->withReservedUnits()->findOrFail($product->getKey())->reservedUnits());

        $this->assertSame(2, $reserved);
    }
}
