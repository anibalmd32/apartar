<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aislamiento sobre los **modelos reales** del tenant (`REQ-ORG-002`).
 *
 * `T-005` probó el trait con un modelo inventado en la suite. Esto es la
 * primera vez que se comprueba sobre `User` y `PaymentAccount`, que es lo que
 * de verdad va a sostener el producto: si `User::query()` dejase de filtrar,
 * el listado de equipo de un negocio mostraría a los empleados de otro.
 */
final class TenantModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_only_visible_from_their_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create(['name' => 'Propio']));
        $this->withinOrganization($neighbour, fn () => User::factory()->create(['name' => 'Ajeno']));

        $this->actingAsOrganization($own);

        $this->assertSame(['Propio'], User::query()->pluck('name')->all());
    }

    public function test_the_neighbour_cannot_see_our_users(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create());

        $visible = $this->withinOrganization($neighbour, fn (): int => User::query()->count());

        $this->assertSame(0, $visible);
        $this->assertTrue($this->hasNeighbourOrganization());
    }

    public function test_payment_accounts_are_only_visible_from_their_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => PaymentAccount::factory()->create(['holder_name' => 'Propio']));
        $this->withinOrganization($neighbour, fn () => PaymentAccount::factory()->create(['holder_name' => 'Ajeno']));

        $this->actingAsOrganization($own);

        $this->assertSame(['Propio'], PaymentAccount::query()->pluck('holder_name')->all());
    }

    /**
     * RB-2: el trait rellena `organization_id`; ningún código lo asigna.
     */
    public function test_creating_fills_the_organization_on_both_models(): void
    {
        $organization = Organization::factory()->create();

        [$user, $account] = $this->withinOrganization($organization, fn (): array => [
            User::factory()->create(),
            PaymentAccount::factory()->create(),
        ]);

        $this->assertSame((int) $organization->getKey(), $user->organization_id);
        $this->assertSame((int) $organization->getKey(), $account->organization_id);
    }

    /**
     * SP-2: un `id` ajeno no existe, no está prohibido. El binding acabará en
     * `404` y no revelará que el recurso existe.
     */
    public function test_a_user_of_another_organization_is_not_found_by_id(): void
    {
        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());

        $this->actingAsOrganization(Organization::factory()->create());

        $this->assertNull(User::query()->find($foreign->getKey()));
    }

    /**
     * SP-3: sin tenant fijado se lanza, no se devuelve todo.
     */
    public function test_querying_users_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        User::query()->get();
    }

    public function test_querying_payment_accounts_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        PaymentAccount::query()->get();
    }

    /**
     * El patrón que va a necesitar el `DevSeeder` de dos organizaciones:
     * sembrar dentro de la vecina se hace situándose en ella, no asignando su
     * `organization_id` a mano.
     */
    public function test_seeding_into_the_neighbour_works_through_within_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());

        $this->assertSame((int) $neighbour->getKey(), $foreign->organization_id);

        // Y el contexto vuelve a la organización de partida.
        $this->assertSame(0, User::query()->count());
    }

    /**
     * La otra cara: asignar la organización ajena a mano mientras se está en
     * la propia lanza, en lugar de guardar una fila que ninguna consulta
     * filtrada volvería a ver del todo.
     */
    public function test_assigning_the_neighbour_organization_by_hand_throws(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        $user = new User(['name' => 'Ajeno', 'email' => 'ajeno@example.test', 'role' => 'member']);
        $user->organization_id = (int) $neighbour->getKey();

        $this->expectException(CrossTenantRelationException::class);

        $user->save();
    }

    /**
     * `users.email` es único en toda la plataforma, no por organización: una
     * persona no puede tener cuenta de equipo en dos negocios con el mismo
     * correo, y eso es intencional en esta iteración.
     */
    public function test_the_same_email_cannot_repeat_across_organizations(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create(['email' => 'duplicado@example.test']));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->withinOrganization(
            $neighbour,
            fn () => User::factory()->create(['email' => 'duplicado@example.test']),
        );
    }

    /**
     * RB-1: saltarse el filtro es posible, pero hay que pedirlo. Es lo que
     * hace el backoffice de plataforma, que opera sin organización.
     */
    public function test_without_tenant_scope_sees_every_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create());
        $this->withinOrganization($neighbour, fn () => User::factory()->create());

        $this->withoutOrganization();

        $this->assertSame(2, User::withoutTenantScope()->count());
    }

    public function test_a_user_relates_back_to_its_organization(): void
    {
        $organization = Organization::factory()->create();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->assertTrue($user->organization->is($organization));
    }
}
