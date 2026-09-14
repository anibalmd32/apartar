<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El modo restringido de una organización suspendida (`REQ-BOF-001` RB-9 y
 * SP-8, `REQ-ORG-008` RB-3).
 *
 * Es la regla del pivote que más va contra el instinto: un negocio que no ha
 * pagado su suscripción **sigue teniendo clientes que le deben dinero**, y
 * cortarle el acceso a cobrarles le impide conseguir con qué pagar. Se le
 * quita crear, no cobrar.
 *
 * Las rutas de cobranza y de comprobantes llegan con H4 y H5, así que aquí se
 * registran dos de prueba montadas como lo estarán ellas: una que declara
 * sobrevivir a la suspensión —aprobar un comprobante— y otra que no —crear un
 * producto—. Lo que se prueba es el mecanismo, que es lo que van a heredar.
 */
final class SuspendedOrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RB-3 · aprobar un comprobante sigue funcionando.
     */
    public function test_a_suspended_team_can_still_approve_a_receipt(): void
    {
        $this->registerBusinessRoutes();

        $this->actingAs($this->teamMember(suspended: true))
            ->post('/__backoffice/comprobantes/1/aprobar')
            ->assertOk()
            ->assertJson(['aprobado' => true]);
    }

    /**
     * SP-3 · y crear un producto no: `403` **con el motivo y adónde ir**, no un
     * error genérico. Quien lo recibe puede resolverlo hoy mismo, y solo lo
     * hará si entiende qué pasó.
     */
    public function test_a_suspended_team_cannot_create_products(): void
    {
        $this->registerBusinessRoutes();

        $response = $this->actingAs($this->teamMember(suspended: true))
            ->post('/__backoffice/productos');

        $response->assertForbidden();
        $this->assertStringContainsString('suspendida', $response->exception?->getMessage() ?? '');
        $this->assertStringContainsString('Suscripción', $response->exception?->getMessage() ?? '');
    }

    /**
     * La misma ruta de creación funciona con la organización activa: sin esto,
     * el caso anterior pasaría igual con un `403` puesto por cualquier otro
     * motivo.
     */
    public function test_the_same_route_works_while_the_organization_is_active(): void
    {
        $this->registerBusinessRoutes();

        $this->actingAs($this->teamMember())
            ->post('/__backoffice/productos')
            ->assertOk()
            ->assertJson(['creado' => true]);
    }

    /**
     * **Falla cerrado**: una ruta que no declara nada queda bloqueada en
     * suspensión. Es lo que evita que una pantalla futura quede abierta porque
     * nadie se acordó de listarla en ningún sitio.
     */
    public function test_a_route_that_declares_nothing_is_blocked(): void
    {
        Route::middleware($this->backofficeStack())
            ->get('__backoffice/sin-declarar', fn (): array => ['ok' => true]);

        $this->actingAs($this->teamMember(suspended: true))
            ->get('/__backoffice/sin-declarar')
            ->assertForbidden();
    }

    /**
     * El panel sobrevive: es donde se ve el aviso y el enlace para
     * regularizar. Bloquearlo dejaría al equipo sin saber por qué no puede
     * hacer nada.
     */
    public function test_the_dashboard_survives_the_suspension(): void
    {
        $this->actingAs($this->teamMember(suspended: true))
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/Dashboard')
                ->where('teamMember.organization.status', 'suspended')
            );
    }

    /**
     * Y el aviso con su enlace de pago se ve **en el marco**, no en el panel:
     * tiene que aparecer esté donde esté la persona.
     */
    public function test_the_layout_carries_the_notice_and_the_payment_link(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/layouts/backoffice-layout.tsx'));

        expect($layout)
            ->toContain("=== 'suspended'")
            ->toContain('/backoffice/suscripcion')
            ->toContain('Regularizar el pago');
    }

    /**
     * Salir también sobrevive: dejar a alguien dentro sin poder cerrar sesión
     * convertiría una restricción en una trampa.
     */
    public function test_logging_out_survives_the_suspension(): void
    {
        $this->actingAs($this->teamMember(suspended: true))
            ->post('/backoffice/logout')
            ->assertRedirect(route('login'));
    }

    /**
     * Dos rutas montadas como las de verdad: la de aprobar declara
     * `during-suspension`, la de crear no.
     */
    private function registerBusinessRoutes(): void
    {
        Route::middleware([...$this->backofficeStack(), 'during-suspension'])
            ->post('__backoffice/comprobantes/{receipt}/aprobar', fn (): array => ['aprobado' => true]);

        Route::middleware($this->backofficeStack())
            ->post('__backoffice/productos', fn (): array => ['creado' => true]);
    }

    /**
     * La cadena del backoffice, la misma de `routes/backoffice.php`.
     *
     * @return list<string>
     */
    private function backofficeStack(): array
    {
        return ['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'];
    }

    private function teamMember(bool $suspended = false): User
    {
        $organization = Organization::factory()->create($suspended
            ? ['status' => OrganizationStatus::Suspended, 'suspended_at' => now()]
            : []);

        $this->neighbourOrganization();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $user;
    }
}
