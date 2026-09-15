<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\ChangeTeamMemberRoleAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El cambio de rol, por debajo de la ruta (`REQ-ORG-003` RB-3).
 *
 * El recorrido por HTTP está en `TeamRoleTest`. Aquí está la asimetría que
 * justifica que la acción no sea un `update()`: **promover suma un `owner`
 * activo y degradar resta uno**, y solo la segunda dirección puede dejar a una
 * organización sin nadie al mando.
 */
final class ChangeTeamMemberRoleActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 10]);

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
     * Promover no pasa por `LastOwnerGuard` y no hace falta: suma un `owner`
     * activo, y esa invariante solo se puede romper restando.
     */
    public function test_promoting_needs_no_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->action()->handle($member, OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $member->refresh()->role);
        $this->assertSame(2, (int) User::query()->where('role', 'owner')->whereNull('disabled_at')->count());
    }

    /**
     * Degradar sí, y la guarda es la de `T-009`: comprueba y escribe dentro de
     * la misma transacción, con las filas de los `owner` bloqueadas.
     */
    public function test_demoting_the_last_active_owner_is_refused(): void
    {
        $owner = User::factory()->owner()->create();

        try {
            $this->action()->handle($owner, OrganizationRole::Member, $owner);
            $this->fail('Degradar al último owner debió fallar.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('degradar', $exception->getMessage());
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    /**
     * Y con otro `owner` activo detrás, la misma degradación pasa. Sin este
     * caso, una guarda que fallara **siempre** pasaría el anterior con nota.
     */
    public function test_the_same_demotion_succeeds_while_another_owner_remains(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->action()->handle($owner, OrganizationRole::Member, $owner);

        $this->assertSame(OrganizationRole::Member, $owner->refresh()->role);
    }

    /**
     * Un `owner` desactivado no ocupa el puesto: `LastOwnerGuard` cuenta los
     * **activos**, y una organización cuyo único `owner` con acceso se degrada
     * se queda igual de sola.
     */
    public function test_a_disabled_owner_does_not_hold_the_seat(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->disabled()->create();

        $this->expectException(LastOwnerException::class);

        $this->action()->handle($owner, OrganizationRole::Member, $owner);
    }

    /**
     * El mismo rol no escribe nada: un `UPDATE` que no cambia nada ensuciaría
     * la auditoría con cambios de rol que no ocurrieron, y haría pasar por el
     * bloqueo a quien no toca la invariante.
     */
    public function test_setting_the_same_role_writes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        $before = $member->updated_at;

        $this->action()->handle($member, OrganizationRole::Member, $owner);

        $this->assertEquals($before, $member->refresh()->updated_at);
    }

    /**
     * Y el último `owner` puede «cambiarse» a `owner` sin que la guarda lo
     * pare: no está restando nada. Es el caso que rompería si el atajo del
     * mismo rol no existiera antes del `match`.
     */
    public function test_the_last_owner_may_be_set_to_owner_again(): void
    {
        $owner = User::factory()->owner()->create();

        $this->action()->handle($owner, OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $owner->refresh()->role);
    }

    /**
     * La policy vive también aquí, no solo en el middleware `owner` del grupo
     * de rutas: una acción invocada desde un job o un comando no pasa por
     * ningún grupo.
     */
    public function test_a_member_cannot_change_anyones_role(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, OrganizationRole::Owner, $actor);
    }

    /**
     * `REQ-ORG-002` RB-7 · alguien de otra organización no se toca. Salta la
     * policy, que compara `organization_id`, **antes** de abrir transacción y
     * de contar los `owner` de la organización equivocada.
     */
    public function test_a_member_of_another_organization_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($theirs, OrganizationRole::Owner, $owner);
    }

    private function action(): ChangeTeamMemberRoleAction
    {
        return app(ChangeTeamMemberRoleAction::class);
    }
}
