<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalDashboardTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Customer $customer;

    private Layaway $first;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->first = $this->layawayIn($this->organization);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->first->customer_id));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_scoped_session_has_one_block_with_live_layaways_and_history(): void
    {
        $this->ownLayaway(LayawayStatus::Active);
        $expired = $this->ownLayaway(LayawayStatus::Expired);

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');
        $this->signIn();

        $this->get(route('portal.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Dashboard')
            ->where('totals', null)
            ->has('businesses', 1)
            ->where('businesses.0.business.name', 'Ferretería Oriente')
            ->where('businesses.0.closed', false)
            ->has('businesses.0.layaways', 2)
            ->has('businesses.0.history', 1)
            ->where('businesses.0.history.0.code', $expired->code)
            ->where('businesses.0.totals.activeLayaways', 2)
            ->where('businesses.0.totals.remaining.cents', 35_000)
            ->where('businesses.0.totals.overdueInstallments', 1)
            ->where('businesses.0.totals.nextDueDate', '2026-09-25'));
    }

    public function test_each_layaway_tells_its_next_amount_and_how_long_overdue(): void
    {
        $active = $this->ownLayaway(LayawayStatus::Active);

        CarbonImmutable::setTestNow('2026-09-27 12:00:00');
        $this->signIn();

        $this->get(route('portal.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('businesses.0.layaways.0.code', $active->code)
            ->where('businesses.0.layaways.0.nextAmount.usd', '$30,00')
            ->where('businesses.0.layaways.0.overdueDays', 2)
            ->where('businesses.0.layaways.1.overdueDays', null)
            ->has('businesses.0.layaways.0.imageUrl'));
    }

    public function test_one_aggregated_query_per_organization(): void
    {
        foreach (range(1, 4) as $i) {
            $this->ownLayaway(LayawayStatus::Active);
        }

        $this->signIn();

        $layawayQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$layawayQueries): void {
            if (preg_match('/^select .* from `layaways`/i', $query->sql) === 1) {
                $layawayQueries++;
            }
        });

        $this->get(route('portal.dashboard'))->assertOk();

        $this->assertSame(1, $layawayQueries);
    }

    public function test_a_suspended_business_looks_normal_and_a_closed_one_says_so(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->signIn();

        $response = $this->get(route('portal.dashboard'))->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('businesses.0.closed', false)->has('businesses.0.layaways', 1));
        $this->assertStringNotContainsStringIgnoringCase('suspend', (string) $response->getContent());

        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();

        $this->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('businesses.0.closed', true));
    }

    public function test_the_props_carry_no_ids(): void
    {
        $this->signIn();

        $props = $this->get(route('portal.dashboard'))->viewData('page')['props'];
        $json = json_encode($props['businesses'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"id"', $json);
        $this->assertStringNotContainsString('customer_id', $json);
        $this->assertStringNotContainsString('account', $json);
    }

    private function signIn(): void
    {
        $this->actingAs($this->customer, 'customer');
    }

    private function ownLayaway(LayawayStatus $status): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $this->customer->getKey()]);

        $this->withinOrganization($this->organization, function () use ($layaway, $status): void {
            if ($status === LayawayStatus::Expired) {
                app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Expired);

                return;
            }

            CarbonImmutable::setTestNow('2026-09-10 10:00:00');
            app(ActivateLayawayAction::class)->handle($layaway);
            CarbonImmutable::setTestNow();
        });
        $this->withoutOrganization();

        return $layaway;
    }
}
