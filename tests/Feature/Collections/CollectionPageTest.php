<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Category;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Queries\CollectionFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CollectionPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

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

    public function test_layaways_awaiting_the_down_payment_are_listed_with_what_is_missing_the_soonest_first(): void
    {
        $later = $this->layawayIn($this->organization);
        $sooner = $this->layawayIn($this->organization);
        $open = $this->layawayIn($this->organization);
        $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $later->getKey())->update(['expires_at' => '2026-09-13 15:00:00']);
        DB::table('layaways')->where('id', $sooner->getKey())->update(['expires_at' => '2026-09-11 15:00:00', 'paid_cents' => 2_000, 'remaining_cents' => 18_000]);
        DB::table('layaways')->where('id', $open->getKey())->update(['expires_at' => null]);

        $this->actingAs($this->member)->get(route('collections.index', ['preset' => 'awaiting_down_payment']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'down_payments')
            ->where('rows', fn (Collection $rows): bool => $rows->pluck('layaway.code')->all() === [$sooner->code, $later->code, $open->code])
            ->where('rows.0.downPayment.cents', 5_000)
            ->where('rows.0.missing.cents', 3_000)
            ->where('rows.1.missing.cents', 5_000)
            ->where('rows.2.expiresAt', null)
            ->where('summary', null)
            ->where('presetCounts.awaiting_down_payment', 3));

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'installments')
            ->where('awaitingDownPayment.count', 3)
            ->where('awaitingDownPayment.missing.cents', 13_000));
    }

    public function test_el_aviso_de_los_que_esperan_la_inicial_respeta_la_categoria_filtrada(): void
    {
        [$shoes, $bags] = $this->withinOrganization($this->organization, fn (): array => [Category::factory()->create(), Category::factory()->create()]);
        $shoe = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['category_id' => $shoes->getKey()]));
        $bag = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->create(['category_id' => $bags->getKey()]));
        $this->withoutOrganization();
        $this->layawayIn($this->organization, product: $shoe);
        $this->layawayIn($this->organization, product: $bag);
        $this->layawayIn($this->organization, product: $bag);

        $this->actingAs($this->member)->get(route('collections.index', ['category' => (string) $shoes->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('awaitingDownPayment.count', 1)
            ->where('awaitingDownPayment.missing.cents', 5_000));

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('awaitingDownPayment.count', 3));
    }

    public function test_without_layaways_awaiting_the_down_payment_there_is_no_notice(): void
    {
        $this->layawayIn($this->organization, LayawayStatus::Active);

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('awaitingDownPayment', null));
    }

    public function test_each_row_is_an_installment_with_what_collecting_needs(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['full_name' => 'María González', 'phone' => '+584141234567']);
        $this->installment($layaway, 1, '2026-09-05', paid: 1_000);

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/collections/Index')
            ->where('kind', 'installments')
            ->has('rows', 1)
            ->where('rows.0.customer', [
                'name' => 'María González',
                'phone' => '+584141234567',
                'phoneLabel' => '0414-1234567',
                'url' => route('customers.show', $layaway->customer_id),
                'chatUrl' => null,
            ])
            ->where('rows.0.layaway.url', route('layaways.show', $layaway))
            ->where('rows.0.number', 1)
            ->where('rows.0.dueDate', '2026-09-05')
            ->where('rows.0.amount.cents', 3_000)
            ->where('rows.0.paid.cents', 1_000)
            ->where('rows.0.daysLate', 5)
            ->has('options.presets', 7));
    }

    public function test_cada_cuota_dice_el_producto_y_se_puede_marcar_entregada_desde_la_tabla(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['product_name_snapshot' => 'Bolso tote']);
        $this->installment($layaway, 1, '2026-09-05');

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.productName', 'Bolso tote')
            ->where('rows.0.delivery', ['deliveredAt' => null, 'url' => route('layaways.deliver', $layaway)]));

        $this->from(route('collections.index'))->post(route('layaways.deliver', $layaway), ['volver' => true])
            ->assertRedirect(route('collections.index'))
            ->assertSessionHas('status', sprintf('Entrega de %s registrada.', $layaway->code));
        $this->withoutOrganization();

        $this->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.delivery', ['deliveredAt' => '2026-09-10', 'url' => null]));
    }

    public function test_agrupar_por_cliente_se_guarda_y_junta_sus_cuotas_en_una_fila(): void
    {
        $first = $this->layawayIn($this->organization, LayawayStatus::Active);
        $second = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $second->getKey())->update(['customer_id' => $first->customer_id]);
        $other = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->installment($first, 1, '2026-09-08');
        $this->installment($second, 1, '2026-09-20', paid: 1_000);
        $this->installment($other, 1, '2026-09-01');

        $this->actingAs($this->member)->put(route('collections.grouping'), ['grouped' => true])->assertRedirect();
        $this->assertTrue((bool) DB::table('users')->where('id', $this->member->getKey())->value('collections_grouped'));

        $this->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'customers')
            ->where('grouped', true)
            ->has('rows', 2)
            ->where('rows.0.id', (int) $other->customer_id)
            ->where('rows.1.id', (int) $first->customer_id)
            ->where('rows.1.count', 2)
            ->where('rows.1.layaways', 2)
            ->where('rows.1.pending.cents', 5_000)
            ->where('rows.1.daysLate', 2)
            ->where('rows.1.installments', fn (Collection $rows): bool => $rows->pluck('layaway.code')->all() === [$first->code, $second->code]));

        $this->put(route('collections.grouping'), ['grouped' => false]);
        $this->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('kind', 'installments')->has('rows', 3));
    }

    public function test_agrupado_cada_apartado_trae_su_avance_y_el_detalle_se_guarda(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 6_000, 'total_cents' => 15_000, 'product_name_snapshot' => 'Mini Ups']);
        $this->installment($layaway, 2, '2026-09-08', paid: 1_000);
        $this->installment($layaway, 3, '2026-09-20');

        $this->actingAs($this->member)->put(route('collections.grouping'), ['grouped' => true])->assertRedirect();

        $this->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail', 'layaways')
            ->has('rows.0.layawayRows', 1)
            ->where('rows.0.layawayRows.0.productName', 'Mini Ups')
            ->where('rows.0.layawayRows.0.progress', 40)
            ->where('rows.0.layawayRows.0.installments', 2)
            ->where('rows.0.layawayRows.0.owed.cents', 5_000)
            ->where('rows.0.layawayRows.0.nextDue', '2026-09-08')
            ->where('rows.0.progress', 40)
            ->where('rows.0.total.cents', 15_000));

        $this->put(route('collections.grouping'), ['detail' => 'installments'])->assertRedirect();
        $this->assertSame('installments', DB::table('users')->where('id', $this->member->getKey())->value('collections_detail'));
        $this->assertTrue((bool) DB::table('users')->where('id', $this->member->getKey())->value('collections_grouped'));
        $this->put(route('collections.grouping'), ['detail' => 'productos'])->assertSessionHasErrors('detail');
    }

    public function test_the_layaway_preset_lists_layaways_with_its_definition(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);

        $this->actingAs($this->member)->get(route('collections.index', ['preset' => 'completed_not_delivered']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'layaways')
            ->where('rows.0.layaway.code', $layaway->code)
            ->where('definition', 'Apartados pagados por completo que todavía no se han entregado.'));
    }

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

    #[DataProvider('presets')]
    public function test_the_page_shows_exactly_what_collection_filters_returns(?CollectionPreset $preset): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $overdue = $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->layawayIn($this->organization);

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

    public function test_it_is_available_while_suspended(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)->get(route('collections.index'))->assertOk();
    }

    public function test_it_summarises_what_is_shown_and_counts_each_preset(): void
    {
        $first = $this->layawayIn($this->organization, LayawayStatus::Active);
        $second = $this->layawayIn($this->organization, LayawayStatus::Active);
        DB::table('layaways')->where('id', $first->getKey())->update(['installments_count' => 4]);
        $this->installment($first, 1, '2026-09-10', paid: 1_000);
        $this->installment($first, 2, '2026-09-25');
        $this->installment($second, 1, '2026-09-12');

        $this->actingAs($this->member)->get(route('collections.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.installments', 3)
            ->where('summary.pending.cents', 3_000 - 1_000 + 3_000 + 3_000)
            ->where('summary.customers', 2)
            ->where('presetCounts.due_today', 1)
            ->where('presetCounts.due_within_7_days', 2)
            ->where('presetCounts.completed_not_delivered', 0)
            ->where('rows.0.number', 1)
            ->where('rows.0.of', 4));

        $this->get(route('collections.index', ['preset' => 'due_today']))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.installments', 1)
            ->where('summary.customers', 1));
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
