<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El cambio de plan desde la pantalla de suscripción (`REQ-SUB-005`,
 * `T-068`): la previsualización viaja con cada plan del escaparate, subir se
 * ve en `planState` en la petición siguiente, y la ruta la gobierna el dueño.
 */
final class ChangePlanPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $free = Plan::factory()->create(['code' => 'free', 'name' => 'Free', 'position' => 1, 'max_products' => 2]);
        Plan::factory()->withEveryFeature()->create(['code' => 'pro', 'name' => 'Pro', 'position' => 2, 'max_products' => 10]);

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);
        $this->owner = $this->withinOrganization($this->organization, function () use ($free): User {
            Subscription::factory()->forPlan($free)->create();

            return User::factory()->owner()->create();
        });
        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();
    }

    /**
     * Cada plan del escaparate llega con su dirección y su previsualización,
     * y la pantalla sabe si quien mira puede cambiar.
     */
    public function test_the_page_carries_every_plan_with_its_preview(): void
    {
        $this->actingAs($this->owner)
            ->get('/backoffice/suscripcion')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/subscription/Show')
                ->where('canChangePlan', true)
                ->where('scheduledChange', null)
                ->has('plans', 2)
                ->where('plans.0.code', 'free')
                ->where('plans.0.isCurrent', true)
                ->where('plans.0.preview.direction', 'same')
                ->where('plans.1.code', 'pro')
                ->where('plans.1.limits.products', 10)
                ->where('plans.1.preview.direction', 'upgrade')
                ->where('plans.1.preview.gainedFeatures', array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases())));
    }

    /**
     * RB-2 · subir se ve **al instante**: la petición siguiente ya trae el
     * plan nuevo en `planState`, con su techo.
     */
    public function test_upgrading_shows_in_the_plan_state_of_the_next_request(): void
    {
        $this->actingAs($this->owner)
            ->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro'])
            ->assertRedirect('/backoffice/suscripcion')
            ->assertSessionHas('status', 'Ya estás en Pro: sus límites y funciones aplican desde ahora.');

        $this->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('planState.planName', 'Pro')
                ->where('planState.usage.products.limit', 10));
    }

    /**
     * SP-2 · la bajada que excede vuelve con el error bajo `confirmed`, sin
     * programar nada; confirmada, se programa y la pantalla la enseña.
     */
    public function test_an_exceeding_downgrade_comes_back_until_it_is_confirmed(): void
    {
        // A las 02:00 UTC del 9 es todavía el 8 en Caracas: la fecha se dice en la zona del negocio.
        CarbonImmutable::setTestNow('2026-10-09 02:00:00 UTC');

        $this->actingAs($this->owner)->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro']);
        $this->withinOrganization($this->organization, fn () => Product::factory()->count(3)->create());
        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();

        $this->from('/backoffice/suscripcion')
            ->post('/backoffice/suscripcion/cambiar', ['plan' => 'free'])
            ->assertRedirect('/backoffice/suscripcion')
            ->assertSessionHasErrors('confirmed');

        $this->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('scheduledChange', null)
                ->where('plans.0.preview.excesses.0.metric', 'products')
                ->where('plans.0.preview.excesses.0.consequence', PlanChangePreview::CONSEQUENCE_BLOCK_CREATION));

        $this->post('/backoffice/suscripcion/cambiar', ['plan' => 'free', 'confirmed' => true])
            ->assertSessionHas('status', 'Pasarás a Free el 08/11/2026. Hasta entonces conservas todo lo de Pro.');

        $this->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('scheduledChange.planCode', 'free')
                ->where('scheduledChange.isCancellation', true)
                ->where('plans.0.isScheduled', true)
                ->where('planState.status', 'grace')
                ->where('planState.planName', 'Pro')
                ->where('timezone', 'America/Caracas'));

        $this->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro'])
            ->assertSessionHas('status', 'Sigues en Pro: el cambio programado quedó cancelado.');
    }

    /** RB-1, SP-1 · un `member` recibe `403` y no cambia nada. */
    public function test_a_member_gets_403(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        $this->actingAs($member)->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro'])->assertForbidden();

        $this->assertSame('free', $this->withinOrganization($this->organization, fn (): string => Subscription::query()->firstOrFail()->plan->code));
    }

    /**
     * Solo se entra en un plan del escaparate; el actual, aunque se haya
     * retirado, sí se admite: es como se revierte (RB-7).
     */
    public function test_a_hidden_plan_is_rejected_but_the_current_one_is_accepted(): void
    {
        Plan::factory()->unlimited()->hidden()->create(['code' => 'secreto', 'position' => 9]);

        $this->actingAs($this->owner)
            ->post('/backoffice/suscripcion/cambiar', ['plan' => 'secreto'])
            ->assertSessionHasErrors(['plan' => 'Ese plan no está a la venta.']);

        Plan::query()->where('code', 'free')->update(['is_public' => false]);

        $this->post('/backoffice/suscripcion/cambiar', ['plan' => 'free'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ya estás en Free.');
    }

    /**
     * `REQ-SUB-006` RB-5, SP-4 · sin RIF, subir a un plan con precio vuelve
     * con el error bajo `plan` —no un `500`— y el plan no cambia.
     */
    public function test_a_priced_upgrade_without_a_tax_id_comes_back_asking_for_it(): void
    {
        Plan::query()->where('code', 'pro')->update(['price_cents' => 1_500]);
        $this->organization->forceFill(['document' => null])->save();

        $this->actingAs($this->owner)
            ->from('/backoffice/suscripcion')
            ->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro'])
            ->assertRedirect('/backoffice/suscripcion')
            ->assertSessionHasErrors(['plan' => OrganizationDocumentRequiredException::MESSAGE]);

        $this->assertSame('free', $this->withinOrganization($this->organization, fn (): string => Subscription::query()->firstOrFail()->plan->code));
    }

    /** SP-8 · cambiar de plan sobrevive a la suspensión: es una vía de regularizar. */
    public function test_changing_the_plan_survives_the_suspension(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)
            ->post('/backoffice/suscripcion/cambiar', ['plan' => 'pro'])
            ->assertRedirect('/backoffice/suscripcion');

        $this->assertSame('pro', $this->withinOrganization($this->organization, fn (): string => Subscription::query()->firstOrFail()->plan->code));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
