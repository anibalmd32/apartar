<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El directorio de clientes (`T-065`, `REQ-BOF-040`).
 */
final class CustomerDirectoryTest extends TestCase
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
     * RB-1, SP-2, SP-3: la misma ficha por su documento escrito de varias
     * formas, por su teléfono con y sin prefijo, por su correo y por su nombre.
     */
    public function test_one_box_finds_the_customer_by_any_of_the_four_criteria(): void
    {
        $maria = $this->customer(['full_name' => 'María José González', 'document_number' => 'V12345678', 'phone' => '+584141234567', 'email' => 'maria@example.com']);
        $this->customer(['full_name' => 'Pedro Pérez', 'document_number' => 'V87654321', 'phone' => '+584121112233']);

        foreach (['12.345.678', 'V-12345678', 'v12345678', '0414-1234567', '+58 414 123 4567', '4141234567', 'MARIA@example.com', 'gonzález', 'maría josé'] as $term) {
            $this->assertSame([$maria->getKey()], $this->idsFor($term), "Buscando «{$term}».");
        }
    }

    /**
     * RB-2, RB-3: los agregados de los apartados vivos —los terminales no
     * cuentan— y el formato; RB-8: de la cuenta, solo si la hay.
     */
    public function test_each_row_carries_the_aggregates_of_live_layaways(): void
    {
        $customer = $this->customer(['full_name' => 'María González', 'document_number' => 'V12345678']);
        $active = $this->layawayFor($customer, LayawayStatus::Active);
        $this->layawayFor($customer, LayawayStatus::Cancelled);
        DB::table('layaways')->where('id', $active->getKey())->update(['paid_cents' => 8_000, 'remaining_cents' => 12_000]);
        $this->installment($active, 1, '2026-09-05');
        $this->installment($active, 2, '2026-09-20');

        $this->actingAs($this->member)->get(route('customers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/customers/Index')
            ->where('rows.0.document', 'V-12.345.678')
            ->where('rows.0.activeLayaways', 1)
            ->where('rows.0.committed.cents', 20_000)
            ->where('rows.0.paid.cents', 8_000)
            ->where('rows.0.remaining.cents', 12_000)
            ->where('rows.0.overdueInstallments', 1)
            ->where('rows.0.nextDueDate', '2026-09-05')
            ->where('rows.0.hasLinkedAccount', false)
            ->missing('rows.0.accountId')
            ->missing('rows.0.account_id'));
    }

    /**
     * RB-6: el orden de la cobranza —más cuotas vencidas primero, después el
     * vencimiento más cercano, al final los que no tienen ninguno—.
     */
    public function test_the_default_order_is_collection_priority(): void
    {
        $calm = $this->customer(['full_name' => 'Ana']);
        $late = $this->customer(['full_name' => 'Beto']);
        $soon = $this->customer(['full_name' => 'Carla']);
        $this->customer(['full_name' => 'Diego']);
        $this->installment($this->layawayFor($calm, LayawayStatus::Active), 1, '2026-10-30');
        $lateLayaway = $this->layawayFor($late, LayawayStatus::Overdue);
        $this->installment($lateLayaway, 1, '2026-08-01');
        $this->installment($lateLayaway, 2, '2026-08-16');
        $this->installment($this->layawayFor($soon, LayawayStatus::Active), 1, '2026-09-12');

        $this->actingAs($this->member)->get(route('customers.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.0.name', 'Beto')
            ->where('rows.1.name', 'Carla')
            ->where('rows.2.name', 'Ana')
            ->where('rows.3.name', 'Diego'));
    }

    /**
     * RB-4: con mora, sin apartados activos y catálogo de origen.
     */
    public function test_the_collection_filters_narrow_the_directory(): void
    {
        $catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->create());
        $late = $this->customer(['full_name' => 'Beto']);
        $idle = $this->customer(['full_name' => 'Diego']);
        $lateLayaway = $this->layawayFor($late, LayawayStatus::Overdue);
        DB::table('layaways')->where('id', $lateLayaway->getKey())->update(['catalog_id' => $catalog->getKey()]);
        $this->installment($lateLayaway, 1, '2026-08-01');
        $this->layawayFor($idle, LayawayStatus::Completed);
        $this->withoutOrganization();
        $this->actingAs($this->member);

        $this->get(route('customers.index', ['overdue' => '1']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Beto'));
        $this->get(route('customers.index', ['without_active' => '1']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Diego'));
        $this->get(route('customers.index', ['catalog' => (string) $catalog->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Beto'));
    }

    /**
     * Lo que se dicta por teléfono llega a trozos: medio teléfono o unas
     * cifras del documento encuentran la ficha, y un número que es a la vez
     * una cédula posible (`0414123`) busca también el teléfono. Medio correo,
     * también.
     */
    public function test_partial_terms_find_the_customer_by_phone_document_or_email(): void
    {
        $maria = $this->customer(['full_name' => 'María González', 'document_number' => 'V12345678', 'phone' => '+584141234567', 'email' => 'mjgonz_80@example.com']);
        $this->customer(['full_name' => 'Pedro Pérez', 'document_number' => 'V87654321', 'phone' => '+584121112233', 'email' => 'mjgonzx80@example.com']);

        // `REQ-IDN-007` SP-6: lo que no se normaliza busca también en el
        // correo; el `_` es una letra, no un comodín.
        foreach (['0414123', '0414-123', '4567', '345678', 'MJGONZ_80', 'gonz_80@exa'] as $term) {
            $this->assertSame([$maria->getKey()], $this->idsFor($term), "Buscando «{$term}».");
        }
    }

    /**
     * «Vencida» no es «en mora» (`REQ-LAY-007` RB-1): hoy es 10 de septiembre y
     * la gracia, 3 días. Una cuota del 9 está vencida pero dentro de la
     * gracia; la del 7 está justo en el límite; la del 6 ya está en mora. Lo
     * que vence hoy no está vencido. El filtro «con mora» y el destacado
     * (RB-4, RB-5) son la mora; la columna, las vencidas, como el portal.
     */
    public function test_arrears_count_the_grace_and_overdue_does_not(): void
    {
        $yesterday = $this->customer(['full_name' => 'Ana']);
        $edge = $this->customer(['full_name' => 'Beto']);
        $late = $this->customer(['full_name' => 'Carla']);
        $today = $this->customer(['full_name' => 'Diego']);
        $this->installment($this->layawayFor($yesterday, LayawayStatus::Active), 1, '2026-09-09');
        $this->installment($this->layawayFor($edge, LayawayStatus::Active), 1, '2026-09-07');
        $this->installment($this->layawayFor($late, LayawayStatus::Overdue), 1, '2026-09-06');
        $this->installment($this->layawayFor($today, LayawayStatus::Active), 1, '2026-09-10');
        $this->withoutOrganization();
        $this->actingAs($this->member);

        $rows = $this->rowsByName();
        $this->assertSame([1, false], [$rows['Ana']['overdueInstallments'], $rows['Ana']['inArrears']]);
        $this->assertSame([1, false], [$rows['Beto']['overdueInstallments'], $rows['Beto']['inArrears']]);
        $this->assertSame([1, true], [$rows['Carla']['overdueInstallments'], $rows['Carla']['inArrears']]);
        $this->assertSame([0, false], [$rows['Diego']['overdueInstallments'], $rows['Diego']['inArrears']]);

        $this->get(route('customers.index', ['overdue' => '1']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Carla'));
    }

    /**
     * RB-4: «vencimiento próximo» llega hasta el primer recordatorio (3 días:
     * el 13), y el rango es del **próximo** vencimiento —el que pinta la
     * fila—, no de cualquier cuota.
     */
    public function test_due_soon_and_the_next_due_range_use_the_next_due_date(): void
    {
        $soon = $this->customer(['full_name' => 'Ana']);
        $later = $this->customer(['full_name' => 'Beto']);
        $november = $this->customer(['full_name' => 'Carla']);
        $soonLayaway = $this->layawayFor($soon, LayawayStatus::Active);
        $this->installment($soonLayaway, 1, '2026-09-13');
        $this->installment($soonLayaway, 2, '2026-11-13');
        $this->installment($this->layawayFor($later, LayawayStatus::Active), 1, '2026-09-14');
        $this->installment($this->layawayFor($november, LayawayStatus::Active), 1, '2026-11-03');
        $this->withoutOrganization();
        $this->actingAs($this->member);

        $this->get(route('customers.index', ['due_soon' => '1']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Ana'));
        $this->get(route('customers.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Carla'));
        $this->get(route('customers.index', ['from' => '2026-09-14']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 2)->where('rows.0.name', 'Beto')->where('rows.1.name', 'Carla'));
        $this->get(route('customers.index', ['to' => '2026-09-13']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('rows.0.name', 'Ana'));
    }

    /**
     * RB-9: los dos roles ven clientes y cartera; un miembro deshabilitado,
     * ninguna de las dos. La sesión ya lo saca antes (`EnsureAdminIsActive`),
     * y las políticas no dependen de ello: un job o un comando no pasa por
     * ese middleware.
     */
    public function test_policies_let_both_roles_in_and_keep_a_disabled_member_out(): void
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $customer = $this->customer(['full_name' => 'Ana']);
        $this->withoutOrganization();

        foreach ([$owner, $this->member] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Customer::class));
            $this->assertTrue(Gate::forUser($user)->allows('view', $customer));
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Layaway::class));
        }

        $this->member->forceFill(['disabled_at' => now()])->save();

        $this->assertFalse(Gate::forUser($this->member)->allows('viewAny', Customer::class));
        $this->assertFalse(Gate::forUser($this->member)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($this->member)->allows('viewAny', Layaway::class));
        $this->actingAs($this->member)->get(route('customers.index'))->assertRedirect();
    }

    /**
     * RB-3, SP-5: sin `N+1`. Dos clientes o seis, las mismas consultas.
     */
    public function test_it_makes_the_same_queries_for_two_customers_or_six(): void
    {
        $count = function (int $customers): int {
            DB::table('installments')->delete();
            DB::table('layaways')->delete();
            DB::table('customers')->delete();

            for ($i = 0; $i < $customers; $i++) {
                $this->installment($this->layawayFor($this->customer(['full_name' => "Cliente {$i}"]), LayawayStatus::Active), 1, '2026-09-20');
            }

            $this->withoutOrganization();
            // Una primera visita calienta lo que se recuerda entre peticiones
            // —ajustes, tasa—; se cuenta la segunda.
            $this->actingAs($this->member)->get(route('customers.index'))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('customers.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(2), $count(6));
    }

    /**
     * RB-7, SP-8: la ficha de otro negocio no existe aquí; buscar su documento
     * no devuelve nada.
     */
    public function test_another_organizations_customers_never_appear(): void
    {
        $this->withinOrganization($this->neighbourOrganization(), fn () => Customer::factory()->create(['document_number' => 'V20000000']));
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('customers.index', ['q' => '20.000.000']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
        $this->get(route('customers.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
    }

    // -------------------------------------------------------------------

    /**
     * @return list<int>
     */
    private function idsFor(string $term): array
    {
        /** @var array{props: array{rows: list<array{id: int}>}} $page */
        $page = $this->actingAs($this->member)->get(route('customers.index', ['q' => $term]))->viewData('page');

        return array_column($page['props']['rows'], 'id');
    }

    /**
     * @return array<string, array{overdueInstallments: int, inArrears: bool}>
     */
    private function rowsByName(): array
    {
        /** @var array{props: array{rows: list<array{name: string, overdueInstallments: int, inArrears: bool}>}} $page */
        $page = $this->get(route('customers.index'))->viewData('page');

        return array_column($page['props']['rows'], null, 'name');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): Customer
    {
        return $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create($attributes + ['document_number' => 'V'.random_int(10_000_000, 99_999_999)]));
    }

    private function layawayFor(Customer $customer, LayawayStatus $status): Layaway
    {
        $layaway = $this->layawayIn($this->organization, $status);
        $orphan = $layaway->customer_id;
        DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $customer->getKey()]);
        DB::table('customers')->where('id', $orphan)->delete();

        return $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }

    private function installment(Layaway $layaway, int $number, string $due): void
    {
        DB::table('installments')->insert([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'number' => $number,
            'due_date' => $due,
            'amount_cents' => 3_000,
            'paid_cents' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
