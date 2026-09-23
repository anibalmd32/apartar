<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El único punto de decisión sobre los límites de plan ([05 §5],
 * `REQ-SUB-004`).
 *
 * La concurrencia —que es lo que justifica el bloqueo— se prueba aparte, en
 * `LimitEnforcerConcurrencyTest`, porque necesita dos conexiones y filas
 * confirmadas. Aquí está todo lo demás.
 */
final class LimitEnforcerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // REQ-QAX-003 RB-10: la vecina existe en todos los escenarios. Si el
        // enforcer contara usuarios sin filtrar por organización, sus tres
        // miembros aparecerían en nuestro consumo.
        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        // El plan de la vecina va **fuera del escaparate**: los casos que
        // comprueban qué plan se ofrece como salida recorren
        // `Plan::public()`, y un plan de atrezo publicado se colaría en esa
        // lista y respondería por el que el test declaró.
        $this->withinOrganization($neighbour, function (): void {
            Subscription::factory()->forPlan(Plan::factory()->hidden()->create())->create();
            User::factory()->count(3)->create();
        });
    }

    /**
     * El caso de `REQ-ORG-004` RB-2, que es el que trae `LimitEnforcer` a esta
     * tarea: **una invitación pendiente consume cupo**.
     *
     * No hace falta sumarla aparte y esa es la gracia: el invitado es una fila
     * de `users` sin contraseña y sin desactivar desde el primer instante
     * (RB-3), así que ya la cuenta la definición de `REQ-SUB-003` RB-2.
     */
    public function test_a_pending_invitation_consumes_a_slot(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            User::factory()->invited()->create();

            $this->assertSame(2, $this->enforcer()->usageOf(UsageMetric::Users)->used);
            $this->assertSame(3, $this->enforcer()->remaining(UsageMetric::Users));
        });
    }

    /**
     * `REQ-ORG-005` RB-5 · un usuario desactivado **libera** su unidad.
     */
    public function test_a_disabled_member_frees_its_slot(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();
            $member = User::factory()->create();

            $this->assertSame(2, $this->enforcer()->synchronise(UsageMetric::Users));

            $member->forceFill(['disabled_at' => now()])->save();

            $this->assertSame(1, $this->enforcer()->synchronise(UsageMetric::Users));
            $this->assertSame(1, $this->enforcer()->usageOf(UsageMetric::Users)->used);
        });
    }

    /**
     * SP-5 · `null` es ilimitado: no bloquea, no tiene porcentaje y no
     * devuelve restante.
     */
    public function test_a_null_limit_never_blocks(): void
    {
        $plan = Plan::factory()->unlimited()->create();
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->count(40)->create();

            $this->assertNull($this->enforcer()->remaining(UsageMetric::Users));

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            // Y sin techo ni siquiera se coge el contador: serializar dos
            // creaciones sería pagar un bloqueo por una pregunta que ya está
            // respondida. Se nota en que el contador sigue como estaba.
            $this->assertSame(0, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
        });
    }

    /**
     * SP-1 · lleno es `422` **con el plan que lo desbloquea**, resuelto por los
     * techos de la matriz y nunca por el código del plan (`REQ-SUB-001` RB-1).
     */
    public function test_a_full_plan_names_the_one_that_unlocks_it(): void
    {
        $free = Plan::factory()->create(['code' => 'free-t013', 'name' => 'Free', 'position' => 1, 'max_users' => 1]);
        Plan::factory()->create(['code' => 'pro-t013', 'name' => 'Pro', 'position' => 2, 'max_users' => 5]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();

            try {
                DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
                $this->fail('El plan lleno debió rechazar la creación.');
            } catch (PlanLimitReachedException $exception) {
                $this->assertSame(UsageMetric::Users, $exception->metric);
                $this->assertSame(1, $exception->used);
                $this->assertSame(1, $exception->limit);
                $this->assertSame('pro-t013', $exception->minimumPlan?->code);
            }
        });
    }

    /**
     * Un plan fuera del escaparate no se ofrece como salida: sigue funcionando
     * para quien ya está dentro, pero no se anuncia (`REQ-SUB-001` RB-6).
     */
    public function test_a_hidden_plan_is_never_offered_as_the_way_out(): void
    {
        $free = Plan::factory()->create(['code' => 'free-h', 'name' => 'Free', 'position' => 1, 'max_users' => 1]);
        Plan::factory()->hidden()->create(['code' => 'negociado', 'name' => 'Negociado', 'position' => 2, 'max_users' => 99]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();

            try {
                DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
                $this->fail('El plan lleno debió rechazar la creación.');
            } catch (PlanLimitReachedException $exception) {
                $this->assertNull($exception->minimumPlan);
            }
        });
    }

    /**
     * RB-8 · con la suscripción suspendida no se crea nada, tenga el contador
     * lo que tenga. Y el motivo que se da es el correcto: no falta sitio,
     * falta estar al día.
     */
    public function test_a_suspended_subscription_blocks_everything(): void
    {
        $plan = Plan::factory()->create(['max_users' => 50]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::factory()->suspended()->forPlan($plan)->create(),
        );

        $this->withinOrganization($this->organization, function (): void {
            $this->expectException(CreationSuspendedException::class);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));
        });
    }

    /**
     * `past_due` **no** bloquea: la gracia existe para que un atraso de pago no
     * paralice el negocio (`REQ-SUB-007` RB-2). Sin este caso, el anterior
     * pasaría también con un enforcer que rechazara cualquier estado que no
     * fuera `active`.
     */
    public function test_a_past_due_subscription_still_creates(): void
    {
        $plan = Plan::factory()->create(['max_users' => 50]);

        $this->withinOrganization(
            $this->organization,
            fn () => Subscription::factory()->pastDue()->forPlan($plan)->create(),
        );

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            // La comprobación llegó hasta el final —no se cortó en el estado
            // de la suscripción— y por el camino dejó el contador cuadrado.
            $this->assertSame(1, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
        });
    }

    /**
     * El contador queda con lo medido, en la misma transacción
     * (`REQ-SUB-003` RB-3). Se inyecta una deriva —el registro deja los seis en
     * cero con el `owner` ya creado— y se comprueba que la comprobación la
     * corrige en vez de fiarse de ella.
     */
    public function test_the_locked_check_rewrites_a_drifted_counter(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->count(3)->create();

            UsageCounter::query()->where('metric', UsageMetric::Users)->update(['value' => 0]);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Users));

            $this->assertSame(3, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
        });
    }

    /**
     * `REQ-SUB-003` RB-6 · las seis métricas viajan a las page props, incluidas
     * las cuatro que todavía no tienen tabla. Un hueco obligaría a cada lectura
     * de la interfaz a contemplar la ausencia.
     */
    public function test_the_state_covers_the_six_metrics(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, function (): void {
            User::factory()->owner()->create();

            $state = $this->enforcer()->state();

            $this->assertCount(6, $state->usage);
            $this->assertSame(1, $state->usageOf(UsageMetric::Users)->used);
            $this->assertSame(0, $state->usageOf(UsageMetric::Products)->used);
            $this->assertSame('active', $state->toArray()['status']);
        });
    }

    /**
     * Las funciones son otra cosa que los límites, y la respuesta lo dice:
     * `403` con el motivo, no `404` (SP-3, RB-7).
     */
    public function test_a_feature_the_plan_lacks_is_unavailable_and_says_which_plan_has_it(): void
    {
        $free = Plan::factory()->create(['code' => 'free-f', 'name' => 'Free', 'position' => 1]);
        Plan::factory()->withEveryFeature()->create(['code' => 'pro-f', 'name' => 'Pro', 'position' => 2]);

        $this->subscribeTo(plan: $free);

        $this->withinOrganization($this->organization, function (): void {
            $this->assertFalse($this->enforcer()->hasFeature(PlanFeature::Branding));

            try {
                $this->enforcer()->assertHasFeature(PlanFeature::Branding);
                $this->fail('La función ausente debió rechazarse.');
            } catch (PlanFeatureUnavailableException $exception) {
                $this->assertSame('pro-f', $exception->minimumPlan?->code);
                $this->assertSame(403, PlanFeatureUnavailableException::STATUS);
            }
        });
    }

    /**
     * Las cuatro métricas cuyas tablas no existen **ya se hacen cumplir**,
     * contra el valor materializado. Es el comportamiento definitivo: cuando
     * `T-066` mantenga los contadores, este será el único camino, y lo que
     * añadirá esa tarea es la medida, no la aplicación del techo.
     */
    public function test_a_metric_without_a_table_yet_is_enforced_from_its_counter(): void
    {
        $plan = Plan::factory()->create(['max_products' => 2]);
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            UsageCounter::query()->where('metric', UsageMetric::Products)->update(['value' => 2]);

            $this->assertSame(2, $this->enforcer()->usageOf(UsageMetric::Products)->used);
            $this->assertSame(0, $this->enforcer()->remaining(UsageMetric::Products));

            // Y `synchronise()` la deja como estaba en vez de ponerla a cero:
            // no saber medir algo no es lo mismo que medir cero.
            $this->assertSame(2, $this->enforcer()->synchronise(UsageMetric::Products));

            $this->expectException(PlanLimitReachedException::class);

            DB::transaction(fn () => $this->enforcer()->assertCanCreate(UsageMetric::Products));
        });
    }

    /**
     * Y la otra mitad de `assertHasFeature`: con la función incluida, pasa sin
     * decir nada.
     */
    public function test_a_feature_the_plan_includes_passes_silently(): void
    {
        $plan = Plan::factory()->withEveryFeature()->create();
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            $this->enforcer()->assertHasFeature(PlanFeature::Branding);

            $this->assertTrue($this->enforcer()->hasFeature(PlanFeature::Branding));
        });
    }

    /**
     * `plan()` devuelve el de la organización activa, no el de la vecina,
     * que tiene el suyo propio en este escenario. Es lo que lee la vista
     * pública del catálogo para decidir a la vez la marca y el sello
     * (`REQ-CAT-021` RB-9).
     */
    public function test_the_plan_is_the_active_organizations(): void
    {
        $plan = Plan::factory()->withEveryFeature()->create(['code' => 'pro-p']);
        $this->subscribeTo(plan: $plan);

        $this->withinOrganization($this->organization, function (): void {
            $this->assertSame('pro-p', $this->enforcer()->plan()->code);
            $this->assertFalse($this->enforcer()->plan()->showsPlatformBadge());
        });
    }

    /**
     * `REQ-ORG-002` RB-1 · el enforcer no pregunta por la organización: la
     * recibe del global scope. Los tres usuarios de la vecina no cuentan para
     * nuestro plan, y nuestro plan no se aplica al suyo.
     */
    public function test_the_neighbours_consumption_is_not_ours(): void
    {
        $this->subscribeTo(maxUsers: 5);

        $this->withinOrganization($this->organization, fn () => User::factory()->owner()->create());

        $ours = $this->withinOrganization(
            $this->organization,
            fn (): int => $this->enforcer()->usageOf(UsageMetric::Users)->used,
        );

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): int => $this->enforcer()->synchronise(UsageMetric::Users),
        );

        $this->assertSame(1, $ours);
        $this->assertSame(3, $theirs);
    }

    // -------------------------------------------------------------------

    private function enforcer(): LimitEnforcer
    {
        return app(LimitEnforcer::class);
    }

    /**
     * Suscribe la organización del escenario, con sus seis contadores a cero
     * como los deja `StartFreeSubscriptionAction`.
     */
    private function subscribeTo(?Plan $plan = null, ?int $maxUsers = null): void
    {
        $plan ??= Plan::factory()->create($maxUsers === null ? [] : ['max_users' => $maxUsers]);

        $this->withinOrganization($this->organization, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();

            foreach (UsageMetric::cases() as $metric) {
                UsageCounter::factory()->forMetric($metric)->create();
            }
        });
    }
}
