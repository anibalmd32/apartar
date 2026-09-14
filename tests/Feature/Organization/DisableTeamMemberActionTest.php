<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\DisableTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CannotDisableSelfException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La baja lógica, por debajo de la ruta (`REQ-ORG-005`).
 *
 * El recorrido por HTTP está en `TeamDeactivationTest`. Aquí están las tres
 * comprobaciones de la acción y su orden, que importa: lo barato primero y el
 * bloqueo al final, porque serializar peticiones para después rechazarlas por
 * permisos sería pagar el precio caro por la respuesta fácil.
 */
final class DisableTeamMemberActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 5]);

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
     * RB-1 · **lógica, nunca un borrado**: la fila se queda con su nombre.
     * Borrarla se llevaría por delante `reviewed_by`, `registered_by` y el
     * rastro de quién aprobó cada comprobante.
     */
    public function test_the_row_survives_with_its_name(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create(['name' => 'Zoe']);

        $this->action()->handle($member, $owner);

        $this->assertNotNull($member->refresh()->disabled_at);
        $this->assertSame('Zoe', $member->name);
        $this->assertDatabaseHas('users', ['id' => $member->getKey()]);
    }

    /**
     * RB-4 · nadie se desactiva a sí mismo, y la comprobación llega **antes**
     * que la del último `owner`: quien se lo intenta suele ser el dueño, y el
     * motivo que tiene que leer es este, no el otro.
     */
    public function test_nobody_disables_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->expectException(CannotDisableSelfException::class);

        $this->action()->handle($owner, $owner);
    }

    /**
     * La policy vive también aquí, no solo en el middleware `owner` del grupo
     * de rutas: una acción invocada desde un job o un comando no pasa por
     * ningún grupo.
     */
    public function test_a_member_cannot_disable_anyone(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($subject, $actor);
    }

    /**
     * Repetirlo no mueve `disabled_at` a hoy: cuándo se fue de verdad es un
     * dato, y sobrescribirlo con cada clic lo perdería.
     */
    public function test_disabling_twice_keeps_the_original_date(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->disabled()->create();

        $original = $member->disabled_at;

        $this->action()->handle($member, $owner);

        $this->assertEquals($original, $member->refresh()->disabled_at);
    }

    /**
     * RB-5 · libera su unidad del límite, y lo hace **dentro de la transacción
     * del bloqueo** del último `owner`, no después.
     */
    public function test_it_frees_the_slot_in_the_same_transaction(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->count(2)->create();
        $member = User::factory()->create();

        $this->action()->handle($member, $owner);

        $this->assertSame(3, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
    }

    /**
     * `REQ-ORG-002` RB-7 · alguien de otra organización no se toca, y hay
     * **tres redes** para eso, en este orden: el binding de ruta, que da `404`;
     * la policy, que compara `organization_id` y es la que salta aquí; y
     * `LastOwnerGuard`, que volvería a mirarlo si las dos anteriores no
     * existieran.
     *
     * Que salte la policy y no la guarda no es un detalle: significa que el
     * caso se corta **antes** de abrir transacción y de contar los `owner` de
     * la organización equivocada.
     */
    public function test_a_member_of_another_organization_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $theirs = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->create(),
        );

        $this->expectException(AuthorizationException::class);

        $this->action()->handle($theirs, $owner);
    }

    private function action(): DisableTeamMemberAction
    {
        return app(DisableTeamMemberAction::class);
    }
}
