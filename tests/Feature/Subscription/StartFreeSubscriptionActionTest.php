<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\OrganizationAlreadySubscribedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El alta automática en la capa gratuita (`REQ-SUB-002`).
 *
 * `RegisterOrganizationAction` la invocará dentro de la transacción del
 * registro (`T-010`); aquí se ejercita sola, que es como se comprueban sus
 * invariantes sin depender de una tarea que todavía no existe.
 */
final class StartFreeSubscriptionActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /**
     * RB-2 · nace `active`, sin factura y **sin fecha de fin**.
     */
    public function test_the_free_subscription_is_born_active_and_open_ended(): void
    {
        $organization = $this->organizationWithNeighbour();

        $subscription = $this->action()->handle($organization);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->current_period_end);
        $this->assertTrue($subscription->isOpenEnded());
        $this->assertNull($subscription->cancelled_at);
        $this->assertNull($subscription->grace_ends_at);

        // El período empieza cuando empieza la suscripción: en Free no hay
        // nada que facturar, así que no hay más fechas que estas dos.
        $this->assertTrue($subscription->started_at->isToday());
        $this->assertTrue($subscription->started_at->equalTo($subscription->current_period_start));
    }

    /**
     * RB-3 · no hay prueba gratuita de plan superior: el plan es Free y la
     * suscripción no tiene reloj. Escribir aquí una fecha de fin
     * reintroduciría el acantilado que el diseño evita.
     */
    public function test_it_subscribes_to_the_free_plan(): void
    {
        $organization = $this->organizationWithNeighbour();

        $subscription = $this->action()->handle($organization);

        $this->assertSame(
            Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->sole()->getKey(),
            $subscription->plan_id,
        );
        $this->assertSame('Free', $subscription->plan->name);
    }

    /**
     * Y queda atada a **su** organización, no a la que hubiera activa en el
     * contexto: la acción se ejecuta dentro de la organización que recibe.
     */
    public function test_it_subscribes_the_organization_it_was_given(): void
    {
        $organization = $this->organizationWithNeighbour();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($neighbour);

        $subscription = $this->action()->handle($organization);

        $this->assertSame((int) $organization->getKey(), $subscription->organization_id);
    }

    /**
     * Y no deja el contexto tocado: `runFor()` restaura lo que había.
     */
    public function test_it_leaves_the_tenant_context_as_it_found_it(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->withoutOrganization();

        $this->action()->handle($organization);

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    /**
     * RB-5 · los seis contadores nacen en cero, y nacen todos: un contador
     * ausente obliga a que cada lectura posterior contemple el hueco.
     */
    public function test_it_initialises_every_counter_at_zero(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        $counters = $this->withinOrganization(
            $organization,
            fn (): array => UsageCounter::query()->get()->all(),
        );

        $this->assertCount(count(UsageMetric::cases()), $counters);

        foreach ($counters as $counter) {
            $this->assertSame(0, $counter->value);
            $this->assertTrue($counter->computed_at->isToday());
        }

        $metrics = array_map(fn (UsageCounter $counter): string => $counter->metric->value, $counters);

        sort($metrics);
        $expected = array_map(fn (UsageMetric $metric): string => $metric->value, UsageMetric::cases());
        sort($expected);

        $this->assertSame($expected, $metrics);
    }

    /**
     * SP-1 · sin plan Free en la base, el alta **falla**. No crea una
     * organización sin plan: ese estado no tiene límites que comprobar y el
     * problema aparecería días después, lejos de su causa.
     */
    public function test_without_a_free_plan_it_fails_instead_of_subscribing_to_nothing(): void
    {
        $organization = $this->organizationWithNeighbour();

        Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->delete();

        try {
            $this->action()->handle($organization);

            $this->fail('Se esperaba MissingFreePlanException.');
        } catch (MissingFreePlanException $exception) {
            $this->assertStringContainsString('PlanSeeder', $exception->getMessage());
        }

        $this->assertSame(0, $this->subscriptionCountOf($organization));
    }

    /**
     * SP-2 · la segunda suscripción es una excepción de dominio, no un
     * `QueryException` con el nombre del índice dentro.
     */
    public function test_a_second_subscription_is_a_domain_exception(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        try {
            $this->action()->handle($organization);

            $this->fail('Se esperaba OrganizationAlreadySubscribedException.');
        } catch (OrganizationAlreadySubscribedException $exception) {
            $this->assertStringContainsString('exactamente una', $exception->getMessage());
        }

        $this->assertSame(1, $this->subscriptionCountOf($organization));
    }

    /**
     * La `UNIQUE` sigue haciendo falta aunque exista la comprobación: dos
     * registros simultáneos pasarían los dos por el `exists()` y solo uno por
     * el índice.
     */
    public function test_the_database_also_refuses_a_second_subscription(): void
    {
        $organization = $this->organizationWithNeighbour();

        $this->action()->handle($organization);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->withinOrganization($organization, fn (): Subscription => Subscription::factory()->create());
    }

    /**
     * SP-3 · si el registro se deshace, no queda suscripción huérfana. Aquí se
     * simula con la transacción del propio llamador, que es lo que hará
     * `RegisterOrganizationAction`.
     */
    public function test_a_rolled_back_registration_leaves_no_subscription(): void
    {
        $organization = $this->organizationWithNeighbour();

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($organization): void {
                $this->action()->handle($organization);

                throw new \RuntimeException('el registro falló después del alta');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('el registro falló después del alta', $exception->getMessage());
        }

        $this->assertSame(0, $this->subscriptionCountOf($organization));
        $this->assertSame(0, $this->counterCountOf($organization));
    }

    private function action(): StartFreeSubscriptionAction
    {
        return app(StartFreeSubscriptionAction::class);
    }

    /**
     * Organización del escenario, con su vecina sembrada (`REQ-QAX-003`
     * RB-10).
     */
    private function organizationWithNeighbour(): Organization
    {
        $organization = Organization::factory()->create();

        $this->neighbourOrganization();
        $this->withoutOrganization();

        return $organization;
    }

    private function subscriptionCountOf(Organization $organization): int
    {
        return $this->withinOrganization(
            $organization,
            fn (): int => Subscription::query()->count(),
        );
    }

    private function counterCountOf(Organization $organization): int
    {
        return $this->withinOrganization(
            $organization,
            fn (): int => UsageCounter::query()->count(),
        );
    }
}
