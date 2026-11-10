<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CatalogSummaryPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_el_resumen_suma_ventas_cobros_e_inventario_del_catalogo(): void
    {
        [$catalog, $ups, $bag] = $this->withinOrganization($this->organization, function (): array {
            $catalog = Catalog::factory()->published()->create(['name' => 'Temporada']);
            $ups = Product::factory()->create(['name' => 'Mini Ups', 'stock' => 10]);
            $bag = Product::factory()->create(['name' => 'Bolso', 'stock' => 5]);
            $catalog->products()->attach($ups, ['position' => 1]);
            $catalog->products()->attach($bag, ['position' => 2]);

            return [$catalog, $ups, $bag];
        });
        $outside = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create());

        $fromCatalog = $this->layawayIn($this->organization, LayawayStatus::Active, $ups);
        DB::table('layaways')->where('id', $fromCatalog->getKey())->update(['catalog_id' => $catalog->getKey(), 'paid_cents' => 8_000, 'remaining_cents' => 12_000]);
        $fromPanel = $this->layawayIn($this->organization, LayawayStatus::Completed, $ups);
        DB::table('layaways')->where('id', $fromPanel->getKey())->update(['paid_cents' => 20_000, 'remaining_cents' => 0]);
        $this->layawayIn($this->organization, LayawayStatus::Cancelled, $bag);
        $this->layawayIn($this->organization, LayawayStatus::Active, $outside);
        $this->insertInstallment($fromCatalog->getKey(), '2026-10-20', 3_000, 1_000);
        $this->insertInstallment($fromCatalog->getKey(), '2026-11-08', 3_000, 0);

        $this->actingAs($this->owner)->get(route('catalogs.summary', $catalog))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/catalogs/Summary')
            ->where('catalog.name', 'Temporada')
            ->where('totals.layaways', 3)
            ->where('totals.units', 2)
            ->where('totals.committed.cents', 40_000)
            ->where('totals.paid.cents', 28_000)
            ->where('totals.remaining.cents', 12_000)
            ->where('totals.progress', 70)
            ->where('totals.overdue.cents', 2_000)
            ->where('totals.overdueLayaways', 1)
            ->where('totals.upcoming.cents', 3_000)
            ->where('totals.awaitingDelivery', 1)
            ->where('statuses.active', 1)
            ->where('statuses.completed', 1)
            ->where('statuses.cancelled', 1)
            ->has('products', 2)
            ->where('products.0.name', 'Mini Ups')
            ->where('products.0.reserved', 1)
            ->where('products.0.sold', 1)
            ->where('products.0.paid.cents', 28_000)
            ->where('products.1.name', 'Bolso')
            ->where('products.1.reserved', 0)
            ->has('series', 6)
            ->has('debtors', 1)
            ->where('debtors.0.remaining.cents', 12_000));
    }

    private function insertInstallment(int $layawayId, string $due, int $amount, int $paid): void
    {
        DB::table('installments')->insert([
            'organization_id' => $this->organization->getKey(),
            'layaway_id' => $layawayId,
            'number' => (int) DB::table('installments')->where('layaway_id', $layawayId)->count() + 1,
            'due_date' => $due,
            'amount_cents' => $amount,
            'paid_cents' => $paid,
            'status' => $paid > 0 ? 'partially_paid' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
