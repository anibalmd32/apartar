<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ReleaseStockAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReleaseStockActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    /**
     * @return array<string, array{0: LayawayStatus, 1: LayawayStatus, 2: bool}>
     */
    public static function terminalTransitions(): array
    {
        return [
            'expirar sin pagar la inicial' => [LayawayStatus::PendingDownPayment, LayawayStatus::Expired, true],
            'cancelar antes de la inicial' => [LayawayStatus::PendingDownPayment, LayawayStatus::Cancelled, true],
            'cancelar uno activo' => [LayawayStatus::Active, LayawayStatus::Cancelled, true],
            'cancelar uno en mora' => [LayawayStatus::Overdue, LayawayStatus::Cancelled, true],
            'completar uno activo' => [LayawayStatus::Active, LayawayStatus::Completed, false],
            'completar uno en mora' => [LayawayStatus::Overdue, LayawayStatus::Completed, false],
        ];
    }

    #[DataProvider('terminalTransitions')]
    public function test_expiring_and_cancelling_return_the_stock_and_completing_does_not(LayawayStatus $from, LayawayStatus $to, bool $returns): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $layaway = $this->layawayIn($this->organization, $from, $product, quantity: 2);

        $this->assertSame(3, $this->stockOf($product), 'El apartado sembrado no reservó sus unidades.');

        app(LayawayStateMachine::class)->transition($layaway, $to);

        $this->assertSame($returns ? 5 : 3, $this->stockOf($product));
        $this->assertSame($returns, DB::table('layaways')->where('id', $layaway->getKey())->value('stock_released_at') !== null);
    }

    public function test_releasing_twice_returns_the_units_once(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $layaway = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product, quantity: 2);

        app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Expired);
        app(ReleaseStockAction::class)->handle($layaway);
        app(ReleaseStockAction::class)->handle(Layaway::query()->findOrFail($layaway->getKey()));

        $this->assertSame(5, $this->stockOf($product));
    }

    #[DataProvider('holdingOrCompleted')]
    public function test_a_layaway_that_does_not_release_is_refused(LayawayStatus $status): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $layaway = $this->layawayIn($this->organization, $status, $product);

        try {
            app(ReleaseStockAction::class)->handle($layaway);
            $this->fail("Se liberó stock de un apartado en «{$status->value}».");
        } catch (LogicException $exception) {
            $this->assertStringContainsString($status->value, $exception->getMessage());
        }

        $this->assertSame(4, $this->stockOf($product));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('stock_released_at'));
    }

    /**
     * @return array<string, array{0: LayawayStatus}>
     */
    public static function holdingOrCompleted(): array
    {
        return [
            'esperando la inicial' => [LayawayStatus::PendingDownPayment],
            'activo' => [LayawayStatus::Active],
            'en mora' => [LayawayStatus::Overdue],
            'completado' => [LayawayStatus::Completed],
        ];
    }

    public function test_the_stock_is_released_with_the_organization_suspended(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $layaway = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product, quantity: 2);

        DB::table('organizations')->where('id', $this->organization->getKey())->update(['status' => 'suspended', 'suspended_at' => now()]);

        app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Expired);

        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_releasing_absorbs_the_shortfall_before_returning_anything(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $first = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product);
        $second = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product);
        $third = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product);

        DB::table('products')->where('id', $product->getKey())->update(['stock' => 0, 'stock_shortfall' => 2]);

        app(LayawayStateMachine::class)->transition($first, LayawayStatus::Cancelled);
        $this->assertSame([0, 1], $this->stockAndShortfallOf($product), 'La primera cancelación puso a la venta una unidad que no existe.');

        app(LayawayStateMachine::class)->transition($second, LayawayStatus::Expired);
        $this->assertSame([0, 0], $this->stockAndShortfallOf($product));

        app(LayawayStateMachine::class)->transition($third, LayawayStatus::Cancelled);
        $this->assertSame([1, 0], $this->stockAndShortfallOf($product));
    }

    public function test_a_layaway_loaded_without_its_product_still_releases(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $layaway = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment, $product, quantity: 2);
        app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Expired);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['stock_released_at' => null]);
        DB::table('products')->where('id', $product->getKey())->update(['stock' => 3]);

        $short = Layaway::query()->select('id')->findOrFail($layaway->getKey());

        app(ReleaseStockAction::class)->handle($short);

        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_a_layaway_that_does_not_exist_releases_nothing(): void
    {
        $ghost = (new Layaway)->forceFill(['id' => 999_999]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no tiene producto');

        app(ReleaseStockAction::class)->handle($ghost);
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->getKey())->value('stock');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function stockAndShortfallOf(Product $product): array
    {
        $row = DB::table('products')->where('id', $product->getKey())->first(['stock', 'stock_shortfall']);
        $this->assertNotNull($row);

        return [(int) $row->stock, (int) $row->stock_shortfall];
    }
}
