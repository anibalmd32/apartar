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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CustomerDirectoryTest extends TestCase
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

    public function test_one_box_finds_the_customer_by_any_of_the_four_criteria(): void
    {
        $maria = $this->customer(['full_name' => 'María José González', 'document_number' => 'V12345678', 'phone' => '+584141234567', 'email' => 'maria@example.com']);
        $this->customer(['full_name' => 'Pedro Pérez', 'document_number' => 'V87654321', 'phone' => '+584121112233']);

        foreach (['12.345.678', 'V-12345678', 'v12345678', '0414-1234567', '+58 414 123 4567', '4141234567', 'MARIA@example.com', 'gonzález', 'maría josé'] as $term) {
            $this->assertSame([$maria->getKey()], $this->idsFor($term), "Buscando «{$term}».");
        }
    }

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

        $this->get(route('customers.index', ['catalog' => (string) $catalog->getKey()]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterCounts', ['all' => 2, 'overdue' => 1, 'due_soon' => 0, 'without_active' => 1]));
    }

    public function test_partial_terms_find_the_customer_by_phone_document_or_email(): void
    {
        $maria = $this->customer(['full_name' => 'María González', 'document_number' => 'V12345678', 'phone' => '+584141234567', 'email' => 'mjgonz_80@example.com']);
        $this->customer(['full_name' => 'Pedro Pérez', 'document_number' => 'V87654321', 'phone' => '+584121112233', 'email' => 'mjgonzx80@example.com']);

        foreach (['0414123', '0414-123', '4567', '345678', 'MJGONZ_80', 'gonz_80@exa'] as $term) {
            $this->assertSame([$maria->getKey()], $this->idsFor($term), "Buscando «{$term}».");
        }
    }

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
            $this->actingAs($this->member)->get(route('customers.index'))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('customers.index'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(2), $count(6));
    }

    public function test_another_organizations_customers_never_appear(): void
    {
        $this->withinOrganization($this->neighbourOrganization(), fn () => Customer::factory()->create(['document_number' => 'V20000000']));
        $this->withoutOrganization();

        $this->actingAs($this->member)->get(route('customers.index', ['q' => '20.000.000']))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
        $this->get(route('customers.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0)->where('filterCounts.all', 0));
    }

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
