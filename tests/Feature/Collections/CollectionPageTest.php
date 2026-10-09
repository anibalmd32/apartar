<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Category;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use App\Queries\CollectionFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La pantalla de cobranza (`T-065`, `REQ-BOF-042`): lo que trae cada fila, los
 * filtros que no se pueden usar, la suspensión y el aislamiento. Los presets
 * en sus límites están en `CollectionFiltersTest`.
 */
final class CollectionPageTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'America/Caracas'));
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-6: una fila por cuota, con el cliente y su teléfono, el apartado, la
     * cuota, el vencimiento, lo que se debe y los días de atraso en el
     * calendario del negocio. Un `member` la ve (cobrar es operación diaria).
     */
    public function test_each_row_is_an_installment_with_what_collecting_needs(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['full_name' => 'María González', 'phone' => '+584141234567']);
        $this->installment($layaway, 1, '2026-09-05', paid: 1_000);

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/collections/Index')
            ->where('kind', 'installments')
            ->has('rows', 1)
            ->where('rows.0.customer', ['name' => 'María González', 'phone' => '+584141234567', 'phoneLabel' => '0414-1234567'])
            ->where('rows.0.layaway.url', route('layaways.show', $layaway))
            ->where('rows.0.number', 1)
            ->where('rows.0.dueDate', '2026-09-05')
            ->where('rows.0.amount.cents', 3_000)
            ->where('rows.0.paid.cents', 1_000)
            ->where('rows.0.daysLate', 5)
            ->has('options.presets', 6));
    }

    /**
     * El preset de apartados cambia la forma de las filas y trae su
     * definición para el estado vacío (SP-1).
     */
    public function test_the_layaway_preset_lists_layaways_with_its_definition(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);

        $this->actingAs($this->member)->get(route('collections.index', ['preset' => 'completed_not_delivered']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'layaways')
            ->where('rows.0.layaway.code', $layaway->code)
            ->where('definition', 'Apartados pagados por completo que todavía no se han entregado.'));
    }

    /**
     * SP-2 y `REQ-BOF-045` SP-4: un rango invertido, un preset o una fecha que
     * no existen, y una categoría de **otro** negocio no se aplican y se
     * dicen; la lista sale sin ellos.
     */
    public function test_filters_that_cannot_be_used_are_ignored_and_reported(): void
    {
        $this->installment($this->layawayIn($this->organization, LayawayStatus::Active), 1, '2026-09-25');
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): Category => Category::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('collections.index', [
            'from' => '2026-09-20',
            'to' => '2026-09-10',
            'preset' => 'todo',
            'category' => (string) $foreign->getKey(),
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('meta.filters.from', null)
            ->where('meta.filters.to', null)
            ->where('meta.ignoredFilters', ['preset', 'from', 'to', 'category']));
    }

    /**
     * RB-1: la pantalla no filtra por su cuenta. Para cada preset, las filas
     * son exactamente las de `CollectionFilters` —la consulta que usarán la
     * exportación (`T-070`) y el envío segmentado (`T-083`)—, en su orden.
     */
    #[DataProvider('presets')]
    public function test_the_page_shows_exactly_what_collection_filters_returns(?CollectionPreset $preset): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $overdue = $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->layawayIn($this->organization, LayawayStatus::Completed);

        foreach (['2026-08-01', '2026-08-20', '2026-09-01', '2026-09-10', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-30', '2026-10-05'] as $i => $due) {
            $this->installment($i % 2 === 0 ? $active : $overdue, $i + 1, $due);
        }

        $expected = $this->withinOrganization($this->organization, fn (): array => (new CollectionFilters($preset))->query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->withoutOrganization();

        $this->assertNotSame([], $expected);
        $this->actingAs($this->member)->get(route('collections.index', ['preset' => $preset?->value]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows', fn (Collection $rows): bool => $rows->pluck('id')->all() === $expected));
    }

    /**
     * @return array<string, array{CollectionPreset|null}>
     */
    public static function presets(): array
    {
        $cases = ['sin preset' => [null]];

        foreach (CollectionPreset::cases() as $preset) {
            $cases[$preset->value] = [$preset];
        }

        return $cases;
    }

    /**
     * Sin `N+1`, en las dos formas de fila: dos filas o seis, las mismas
     * consultas.
     */
    public function test_it_makes_the_same_queries_for_two_rows_or_six(): void
    {
        foreach ([LayawayStatus::Active, LayawayStatus::Completed] as $status) {
            $preset = $status === LayawayStatus::Completed ? 'completed_not_delivered' : null;
            $count = function (int $rows) use ($status, $preset): int {
                DB::table('installments')->delete();
                DB::table('layaways')->update(['status' => LayawayStatus::Cancelled->value]);

                for ($i = 0; $i < $rows; $i++) {
                    $this->installment($this->layawayIn($this->organization, $status), 1, '2026-09-20');
                }

                $this->withoutOrganization();
                // Una primera visita calienta lo que se recuerda entre
                // peticiones; se cuenta la segunda.
                $this->actingAs($this->member)->get(route('collections.index', ['preset' => $preset]))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', $rows));
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->get(route('collections.index', ['preset' => $preset]))->assertOk();
                DB::disableQueryLog();

                return count(DB::getQueryLog());
            };

            $this->assertSame($count(2), $count(6), "Filas de apartados {$status->value}.");
        }
    }

    /**
     * RB-8: la cartera de otro negocio no aparece nunca, ni buscando a su
     * cliente.
     */
    public function test_another_organizations_installments_never_appear(): void
    {
        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirs = $this->layawayIn($neighbour, LayawayStatus::Active);
        DB::table('customers')->where('id', $theirs->customer_id)->update(['document_number' => 'V20000000']);
        $this->installment($theirs, 1, '2026-09-12');
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
        $this->get(route('collections.index', ['q' => 'V20000000']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
    }

    /**
     * RB-9: con la organización suspendida, la cartera se sigue viendo.
     */
    public function test_it_is_available_while_suspended(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)->get(route('collections.index'))->assertOk();
    }

    private function installment(Layaway $layaway, int $number, string $due, int $paid = 0): void
    {
        DB::table('installments')->insert([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'number' => $number,
            'due_date' => $due,
            'amount_cents' => 3_000,
            'paid_cents' => $paid,
            'status' => $paid > 0 ? 'partially_paid' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
