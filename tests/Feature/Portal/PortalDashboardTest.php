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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La portada del portal con la sesión acotada (`REQ-LAY-020`).
 *
 * Con la sesión acotada hay exactamente un bloque —el negocio de la ficha— y
 * ningún agregado de todos los negocios (RB-3). Lo que se afirma es lo que va
 * en ese bloque, de dónde sale y cuánto cuesta.
 */
final class PortalDashboardTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

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

    /**
     * RB-3 y RB-5: un bloque, `totals` nulo, los vivos a la vista y los
     * terminales en el historial; los totales del bloque, solo de los vivos y
     * desde los balances materializados.
     *
     * Las cuotas vencidas son las de fecha ya pasada en el calendario del
     * negocio, estén o no en mora (`DueCalendar`): el 27/09 la del 25/09 sí,
     * aunque siga en la gracia, y la del 10/10 todavía no.
     */
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
            ->where('businesses.0.totals.remaining.cents', 40_000)
            ->where('businesses.0.totals.overdueInstallments', 1)
            ->where('businesses.0.totals.nextDueDate', '2026-09-25'));
    }

    /**
     * RB-6 y RB-9: una sola consulta de apartados para el negocio, también con
     * varios apartados y sus cuotas en mora.
     */
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

    /**
     * RB-12: con el negocio suspendido se ve todo con normalidad, y nada dice
     * que el negocio no le paga a la plataforma. SP-9: cerrado sí se dice.
     */
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

    /**
     * RB-10: ningún `id` ni dato de otra ficha en las props.
     */
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

    /**
     * Otro apartado de la misma ficha, llevado a ese estado. Activado el 10/09:
     * la primera cuota vence el 25/09.
     */
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
