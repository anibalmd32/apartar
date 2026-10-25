<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ReleaseStockAction;
use App\Actions\Layaway\ReserveStockAction;
use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsStockLockOrder;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class StockLockOrderTest extends TestCase
{
    use AssertsStockLockOrder, CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
    }

    public function test_reserving_locks_the_product_first(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        $this->assertLocksTheProductFirst(fn () => app(ReserveStockAction::class)->handle($product, 1), 'ReserveStockAction');
    }

    /**
     * @return array<string, array{0: LayawayStatus}>
     */
    public static function releasingTransitions(): array
    {
        return ['expirar' => [LayawayStatus::Expired], 'cancelar' => [LayawayStatus::Cancelled]];
    }

    #[DataProvider('releasingTransitions')]
    public function test_a_transition_that_returns_stock_locks_the_product_before_the_layaway(LayawayStatus $to): void
    {
        $layaway = $this->layawayIn($this->organization);
        $fresh = Layaway::query()->findOrFail($layaway->getKey());

        $this->assertLocksTheProductFirst(fn () => app(LayawayStateMachine::class)->transition($fresh, $to), "transición a {$to->value}");
    }

    public function test_releasing_on_its_own_locks_the_product_first(): void
    {
        $layaway = $this->layawayIn($this->organization);
        app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Expired);

        Layaway::query()->whereKey($layaway->getKey())->update(['stock_released_at' => null]);
        $fresh = Layaway::query()->findOrFail($layaway->getKey());

        $this->assertLocksTheProductFirst(fn () => app(ReleaseStockAction::class)->handle($fresh), 'ReleaseStockAction');
    }

    public function test_editing_a_product_locks_it_before_any_other_row(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create(['stock' => 3]);

        $data = new ProductData(
            name: $product->name,
            sku: $product->sku,
            slug: $product->slug,
            description: 'Una nevera.',
            price: $product->price_cents,
            compareAtPrice: null,
            downPayment: $product->down_payment_cents,
            installmentsCount: $product->installments_count,
            frequencyDays: $product->frequency_days,
            stock: 5,
            categoryName: 'Neveras',
            tagNames: ['Frío'],
        );

        $this->assertLocksTheProductFirst(fn () => app(UpsertProductAction::class)->handle($data, $owner, $product), 'UpsertProductAction');
    }

    public function test_the_inverted_order_is_detected(): void
    {
        $violation = $this->stockLockOrderViolation([
            'select * from `layaways` where `layaways`.`id` = ? and `layaways`.`organization_id` = ? limit 1 for update',
            'select * from `products` where `products`.`id` = ? and `products`.`organization_id` = ? limit 1 for update',
        ]);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('`layaways`', $violation);

        $this->assertNull($this->stockLockOrderViolation([
            'select * from `layaways` where `layaways`.`id` = ? limit 1',
            'select * from `products` where `products`.`id` = ? and `products`.`deleted_at` is null limit 1 for update',
            'update `layaways` set `status` = ? where `id` = ?',
        ]), 'Una lectura simple antes del bloqueo no invierte el orden, y lo de después ya no cuenta.');

        $this->assertNotNull($this->stockLockOrderViolation([
            'update `usage_counters` set `value` = ? where `id` = ?',
            'select * from `products` where `products`.`id` = ? limit 1 for update',
        ]), 'Un UPDATE bloquea su fila igual que un FOR UPDATE.');
    }
}
