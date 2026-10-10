<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La pantalla de suscripción y el estado del plan que comparte cada página del
 * backoffice (`REQ-SUB-003` RB-6, RB-7, `REQ-SUB-004` RB-7, `T-067`).
 */
final class SubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->owner = $this->withinOrganization($this->organization, function (): User {
            Plan::factory()->unlimited()->withEveryFeature()->create(['code' => 'pro-pg', 'name' => 'Pro', 'position' => 2]);
            Subscription::factory()->forPlan(Plan::factory()->create([
                'code' => 'free-pg', 'name' => 'Free', 'position' => 1, 'max_products' => 10, 'features' => PlanFeature::noneEnabled(),
            ]))->create();

            Product::factory()->count(8)->create();

            return User::factory()->owner()->create();
        });

        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();
    }

    /**
     * El plan, su consumo y, a partir del 80 %, el plan que da más sitio; y
     * las funciones que faltan con el que las trae. Todo resuelto en el
     * servidor: la pantalla no decide nada.
     */
    public function test_the_owner_sees_the_plan_its_usage_and_what_unlocks_more(): void
    {
        $this->actingAs($this->owner)
            ->get('/backoffice/suscripcion')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/subscription/Show')
                ->where('planState.planName', 'Free')
                ->where('planState.usage.products.used', 8)
                ->where('planState.usage.products.percentage', 80)
                ->where('planState.upgrades.products.planName', 'Pro')
                ->missing('planState.upgrades.users')
                ->where('planState.featureUpgrades.excel_export.planName', 'Pro')
                ->where('planState.featureLabels.excel_export', PlanFeature::ExcelExport->label())
                ->where('planState.metricLabels.products', UsageMetric::Products->label()));
    }

    /** Es donde se regulariza: sobrevive a la suspensión (`REQ-ORG-008` RB-3). */
    public function test_it_survives_the_suspension(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')->assertOk();
    }

    /** El plan lo gobierna el dueño (`SubscriptionPolicy::view`). */
    public function test_a_member_cannot_open_it(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        $this->actingAs($member)->get('/backoffice/suscripcion')->assertForbidden();
    }

    /**
     * Revisión de `T-067` · alguien del equipo de **otro** negocio, con su
     * sesión abierta, mira el catálogo público de este: la página no puede
     * llevar el plan, el consumo ni el estado de pago de este negocio.
     */
    public function test_a_team_member_of_another_business_never_gets_this_plan_on_its_public_pages(): void
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($stranger)
            ->get('/c/'.$catalog->slug)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('planState', null));
    }

    /** Y el mismo estado viaja con cualquier otra página del backoffice. */
    public function test_every_backoffice_page_carries_the_plan_state(): void
    {
        $this->actingAs($this->owner)
            ->get('/backoffice/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('planState.usage.products.used', 8));
    }
}
