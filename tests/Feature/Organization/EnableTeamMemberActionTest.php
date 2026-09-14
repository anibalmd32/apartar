<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\EnableTeamMemberAction;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La reactivación, por debajo de la ruta (`REQ-ORG-005` RB-6 y SP-4).
 *
 * Dos reglas y las dos son fáciles de perder de vista: **reactivar consume
 * cupo igual que invitar** —sin eso, bajar y subir a la gente sería la forma
 * de rebasar cualquier plan sin pagarlo— y **fuerza el cambio de contraseña**,
 * porque entre la baja y el alta puede haber pasado cualquier cosa con esa
 * credencial y nadie lo va a comprobar.
 */
final class EnableTeamMemberActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['name' => 'Tres', 'position' => 1, 'max_users' => 3]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
                UsageCounter::factory()->forMetric(UsageMetric::Users)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->actingAsOrganization($this->organization);
    }

    /**
     * RB-6 · devuelve el acceso y **obliga a elegir una contraseña nueva**.
     */
    public function test_it_restores_access_and_forces_a_password_change(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $this->action()->handle($member, $owner);

        $member->refresh();

        $this->assertNull($member->disabled_at);
        $this->assertTrue($member->must_change_password);
    }

    /**
     * SP-4 · consume cupo, y con el plan lleno se rechaza con el `422` del
     * límite. Sin esta comprobación, el techo de usuarios no significaría nada:
     * bastaría con desactivar a uno, invitar a dos y volver a subir al primero.
     */
    public function test_it_consumes_a_slot_and_is_rejected_when_the_plan_is_full(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        User::factory()->count(2)->create();

        try {
            $this->action()->handle($member, $owner);
            $this->fail('Reactivar con el plan lleno debió fallar.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Users, $exception->metric);
        }

        $this->assertNotNull($member->refresh()->disabled_at, 'La reactivación rechazada dejó la baja a medias.');
    }

    /**
     * Y el contador queda cuadrado en la misma transacción cuando sí entra.
     */
    public function test_the_counter_moves_with_the_reactivation(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $this->action()->handle($member, $owner);

        $this->assertSame(2, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
    }

    /**
     * Reactivar a quien nunca se fue no le exige cambiar la contraseña: sería
     * la peor forma de no hacer nada.
     */
    public function test_reenabling_an_active_member_changes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->action()->handle($member, $owner);

        $this->assertFalse($member->refresh()->must_change_password);
    }

    /**
     * La policy vive también aquí, no solo en el middleware `owner` del grupo
     * de rutas.
     */
    public function test_a_member_cannot_reenable_anyone(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->disabled()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, $actor);
    }

    /**
     * No pasa por `LastOwnerGuard` y no hace falta: reactivar **suma** un
     * `owner` activo o deja el número como estaba, y esa invariante solo se
     * puede romper restando.
     */
    public function test_reenabling_an_owner_needs_no_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $second = User::factory()->owner()->disabled()->create();

        $this->action()->handle($second, $owner);

        $this->assertSame(2, (int) User::query()->where('role', 'owner')->whereNull('disabled_at')->count());
    }

    private function action(): EnableTeamMemberAction
    {
        return app(EnableTeamMemberAction::class);
    }
}
