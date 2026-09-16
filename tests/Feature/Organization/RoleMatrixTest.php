<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La matriz de `REQ-ORG-003` RB-1 comprobada **por HTTP**, que es como la vive
 * un `member` que escribe a mano la URL de facturación.
 *
 * El enum ya fija el reparto y `OrganizationRoleTest` lo comprueba; lo que se
 * comprueba aquí es lo otro que pide RB-4: que el reparto esté **enchufado al
 * servidor**. Una matriz perfecta en el dominio y una policy que nadie invoca
 * dan exactamente el mismo resultado que no tener roles.
 *
 * Las rutas son de prueba y se registran en el propio test: las de equipo,
 * ajustes y suscripción llegan con `T-013`, `T-016` y `T-011`. Lo que se
 * ejercita es el mecanismo —tenant, policy, `403`—, montado igual que lo
 * estarán ellas.
 *
 * Las celdas se derivan del enum y no de una lista escrita a mano: un área de
 * gobierno nueva trae sus dos casos sola, y si el `match` que autoriza no sabe
 * qué hacer con ella, el test falla en lugar de dejarla sin comprobar.
 */
final class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las seis áreas de gobierno × los dos roles: doce celdas, con el `403`
     * del `member` en cada una de las seis (SP-1).
     *
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    public static function governanceMatrix(): array
    {
        return self::matrixOver(OrganizationCapability::ownerOnly(), memberStatus: 403);
    }

    /**
     * Las seis áreas de operación diaria × los dos roles. Esta mitad importa
     * tanto como la otra: un `member` que recibiera `403` al conciliar un
     * comprobante no sería más seguro, sería inútil, y el `owner` acabaría
     * haciéndolo todo con su propia cuenta.
     *
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    public static function dailyMatrix(): array
    {
        return self::matrixOver(OrganizationCapability::shared(), memberStatus: 200);
    }

    /**
     * @return array<string, array{0: OrganizationRole, 1: int}>
     */
    public static function roles(): array
    {
        return [
            'owner' => [OrganizationRole::Owner, 200],
            'member' => [OrganizationRole::Member, 403],
        ];
    }

    #[DataProvider('governanceMatrix')]
    public function test_each_governance_area_answers_by_role(
        OrganizationCapability $capability,
        OrganizationRole $role,
        int $expected,
    ): void {
        $url = $this->probeRouteFor($capability);

        $this->actingAs($this->actor($role))->get($url)->assertStatus($expected);
    }

    #[DataProvider('dailyMatrix')]
    public function test_both_roles_run_the_daily_work(
        OrganizationCapability $capability,
        OrganizationRole $role,
        int $expected,
    ): void {
        $url = $this->probeRouteFor($capability);

        $this->actingAs($this->actor($role))->get($url)->assertStatus($expected);
    }

    /**
     * RB-4 · el otro cierre, el que protege el camino entero en lugar de la
     * operación concreta: un grupo de rutas del dueño.
     */
    #[DataProvider('roles')]
    public function test_a_group_of_owner_routes_is_closed_to_a_member(OrganizationRole $role, int $expected): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'owner'])
            ->get('__roles/seccion-del-dueno', fn (): array => ['ok' => true]);

        $this->actingAs($this->actor($role))->get('/__roles/seccion-del-dueno')->assertStatus($expected);
    }

    /**
     * Y no redirige al acceso a quien ya entró: un `member` autenticado recibe
     * `403`, no la pantalla de login, porque el problema no es quién es sino
     * qué puede.
     */
    public function test_an_authenticated_member_gets_403_and_not_the_login_screen(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'owner'])
            ->get('__roles/solo-dueno', fn (): array => ['ok' => true]);

        $response = $this->actingAs($this->actor(OrganizationRole::Member))->get('/__roles/solo-dueno');

        $response->assertForbidden();
        $this->assertFalse($response->isRedirect());
    }

    /**
     * La mitad de la policy que no es el rol: el `owner` de un negocio no
     * gobierna el de al lado.
     *
     * Por la URL no llegaría —el binding de otra organización da `404`
     * (`REQ-ORG-002` RB-6)—, pero una `Action` que resuelva el modelo por su
     * cuenta se queda sin esa red, y entonces la única comprobación es esta.
     */
    public function test_an_owner_does_not_govern_the_organization_next_door(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $owner = $this->withinOrganization($own, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('viewSettings', $own));
        $this->assertFalse(Gate::forUser($owner)->allows('viewSettings', $neighbour));
        $this->assertFalse(Gate::forUser($owner)->allows('managePaymentAccounts', $neighbour));
        $this->assertFalse(Gate::forUser($owner)->allows('operate', $neighbour));
    }

    public function test_an_owner_does_not_touch_the_team_next_door(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $owner = $this->withinOrganization($own, fn (): User => User::factory()->owner()->create());
        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());
        $colleague = $this->withinOrganization($own, fn (): User => User::factory()->create());

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $colleague));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $foreign));
        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreign));
    }

    /**
     * RB-3 · un `owner` puede ascender a un `member` y puede degradarse a sí
     * mismo. Lo único que lo detiene es ser el último, y eso no es un permiso:
     * es la invariante de `LastOwnerGuard`, que se comprueba al escribir.
     */
    public function test_an_owner_may_change_roles_including_its_own(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        /** @var array{0: User, 1: User} $team */
        $team = $this->withinOrganization($organization, fn (): array => [
            User::factory()->owner()->create(),
            User::factory()->create(),
        ]);

        [$owner, $member] = $team;

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $member));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($member)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($member)->allows('create', User::class));
    }

    // -------------------------------------------------------------------
    // El escenario
    // -------------------------------------------------------------------

    /**
     * Una celda por capacidad y rol, derivada del enum.
     *
     * @param  list<OrganizationCapability>  $capabilities
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    private static function matrixOver(array $capabilities, int $memberStatus): array
    {
        $cells = [];

        foreach ($capabilities as $capability) {
            foreach (OrganizationRole::cases() as $role) {
                $cells[$capability->value.' · '.$role->value] = [
                    $capability,
                    $role,
                    $role === OrganizationRole::Owner ? 200 : $memberStatus,
                ];
            }
        }

        return $cells;
    }

    /**
     * Registra la ruta de prueba de una capacidad y devuelve su URL.
     *
     * El `match` es exhaustivo: una capacidad nueva sin forma declarada de
     * autorizarse rompe aquí, que es donde se nota, y no en producción.
     */
    private function probeRouteFor(OrganizationCapability $capability): string
    {
        $uri = '__roles/'.$capability->value;

        Route::middleware(['web', 'auth', 'tenant'])->get($uri, function () use ($capability) {
            $organization = app(TenantContext::class)->organization();

            match ($capability) {
                // Desde `T-011` la suscripción existe y su policy se descubre
                // por convención: se autoriza sobre la fila, no sobre un gate
                // con nombre.
                OrganizationCapability::Subscription => Gate::authorize('view', Subscription::query()->sole()),
                OrganizationCapability::Billing => Gate::authorize('viewInvoices', Subscription::query()->sole()),
                OrganizationCapability::Team => Gate::authorize('viewAny', User::class),
                OrganizationCapability::Settings => Gate::authorize('viewSettings', $organization),
                OrganizationCapability::PaymentAccounts => Gate::authorize('managePaymentAccounts', $organization),
                OrganizationCapability::Branding => Gate::authorize('manageBranding', $organization),
                OrganizationCapability::Audit => Gate::authorize('viewAuditLog', $organization),

                OrganizationCapability::Products,
                OrganizationCapability::Catalogs,
                OrganizationCapability::Customers,
                OrganizationCapability::Collections,
                OrganizationCapability::Receipts,
                OrganizationCapability::Chat => Gate::authorize('operate', $organization),
            };

            return response()->json(['area' => $capability->value]);
        });

        return '/'.$uri;
    }

    /**
     * Crea a quien pide, con su rol, en una organización que tiene vecina
     * (`REQ-QAX-003` RB-10) y su suscripción.
     *
     * La suscripción hace falta desde `T-011`: las dos áreas de gobierno que
     * gestiona `SubscriptionPolicy` se autorizan sobre la fila, y toda
     * organización tiene exactamente una (`REQ-SUB-002` RB-1), así que el
     * escenario sin ella no existe fuera de un test.
     */
    private function actor(OrganizationRole $role): User
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $actor = $this->withinOrganization($organization, function () use ($role): User {
            Subscription::factory()->create();

            return User::factory()->state(['role' => $role])->create();
        });

        $this->withoutOrganization();

        return $actor;
    }
}
