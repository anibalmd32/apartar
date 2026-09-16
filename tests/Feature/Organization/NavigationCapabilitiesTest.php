<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La frontera de `REQ-ORG-003` RB-4 se declara **una sola vez**.
 *
 * `OrganizationRole::capabilities()` se escribió en `T-009` «para pintar el
 * menú sin repetir la frontera» y hasta `T-014` no tuvo un solo llamante:
 * `backoffice-layout.tsx` la volvía a declarar a mano con un `ownerOnly` por
 * sección. Lo detectó `T-013b` repasando RB-4.
 *
 * Coincidían, porque eran dos entradas. El problema era cuándo dejarían de
 * coincidir: cada hito añade secciones, y el día que una capacidad cambiara de
 * lado el enum lo sabría y el menú no —sin un test en rojo, y con un `member`
 * viendo una puerta que le va a dar `403`—. Este archivo es el test que faltaba
 * para que eso no pueda pasar en silencio.
 */
final class NavigationCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    /**
     * Las capacidades viajan en las props compartidas, que es lo que permite
     * al menú filtrar sin volver a decidir el reparto.
     */
    public function test_the_capabilities_travel_with_the_team_member(): void
    {
        $owner = $this->memberWith(OrganizationRole::Owner);

        $this->actingAs($owner)
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teamMember.role', 'owner')
                ->has('teamMember.capabilities', 13));
    }

    /**
     * Y un `member` recibe **las seis de operación diaria**, ni una más.
     *
     * Esa mitad es la que hace útil el rol: un empleado tiene que poder cobrar
     * y conciliar comprobantes sin tocar la facturación ni dar de alta
     * usuarios. Si se encoge, el `owner` acaba haciéndolo todo.
     */
    public function test_a_member_only_receives_the_shared_half(): void
    {
        $member = $this->memberWith(OrganizationRole::Member);

        $response = $this->actingAs($member)->get('/backoffice')->assertOk();

        $capabilities = $this->capabilitiesIn($response);

        $this->assertCount(6, $capabilities);
        $this->assertNotContains('team', $capabilities);
        $this->assertNotContains('settings', $capabilities);
        $this->assertNotContains('subscription', $capabilities);
        $this->assertContains('collections', $capabilities);
        $this->assertContains('receipts', $capabilities);
    }

    /**
     * **Lo que llega a las props es exactamente lo que dice el enum.** Es la
     * aserción que cierra el agujero: si alguien añadiera un área nueva sin
     * decidir de qué lado cae, o cambiara `requiresOwner()`, aquí se ve.
     */
    public function test_the_shared_props_mirror_the_enum_exactly(): void
    {
        foreach ([OrganizationRole::Owner, OrganizationRole::Member] as $role) {
            $expected = array_map(
                fn (OrganizationCapability $capability): string => $capability->value,
                $role->capabilities(),
            );

            $this->app->make('auth')->forgetGuards();

            $response = $this->actingAs($this->memberWith($role))->get('/backoffice')->assertOk();

            $this->assertSame($expected, $this->capabilitiesIn($response), 'El rol '.$role->value.' no recibe lo que dice el enum.');
        }
    }

    /**
     * Y las secciones que hoy pinta el menú declaran capacidades que el enum
     * conoce. Un `capability` mal escrito en el `.tsx` no lo ve TypeScript
     * —sería una cadena cualquiera dentro de la unión— pero sí lo ve esto.
     */
    public function test_every_section_of_the_menu_names_a_real_capability(): void
    {
        $layout = (string) file_get_contents(resource_path('js/layouts/backoffice-layout.tsx'));

        preg_match_all("/capability: '([a-z_]+)'/", $layout, $matches);

        $declared = array_values(array_diff($matches[1], ['any']));

        $known = array_map(
            fn (OrganizationCapability $capability): string => $capability->value,
            OrganizationCapability::cases(),
        );

        $this->assertNotSame([], $declared, 'El menú no declara ninguna capacidad: ¿volvió el ownerOnly?');
        $this->assertSame([], array_values(array_diff($declared, $known)));
    }

    /**
     * Y ninguna sección vuelve al `ownerOnly`, que es la forma que tenía la
     * segunda fuente de verdad.
     */
    public function test_no_section_declares_its_role_by_hand(): void
    {
        foreach (['js/layouts/backoffice-layout.tsx', 'js/layouts/backoffice/nav.tsx'] as $file) {
            // Se busca `ownerOnly:` con sus dos puntos —la forma que tiene en
            // una propiedad— y no el nombre suelto: los docblocks de esos dos
            // archivos lo mencionan justamente para explicar por qué ya no
            // está, y prohibir la palabra prohibiría contar la historia.
            $this->assertStringNotContainsString(
                'ownerOnly:',
                (string) file_get_contents(resource_path($file)),
                $file.' vuelve a declarar el rol a mano: la frontera de RB-4 es de OrganizationCapability.',
            );
        }
    }

    /**
     * Las capacidades que viajaron en las props compartidas de esa respuesta.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return list<string>
     */
    private function capabilitiesIn(TestResponse $response): array
    {
        /** @var array{props: array{teamMember: array{capabilities: list<string>}}} $page */
        $page = $response->viewData('page');

        return $page['props']['teamMember']['capabilities'];
    }

    private function memberWith(OrganizationRole $role): User
    {
        $user = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->create(['role' => $role]),
        );

        $this->withoutOrganization();

        return $user;
    }
}
