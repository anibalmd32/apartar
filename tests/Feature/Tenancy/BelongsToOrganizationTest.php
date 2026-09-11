<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Domain\Tenancy\TenantScope;
use App\Models\Organization;
use Tests\Support\TenantTestCase;
use Tests\Support\TenantTestRecord;

/**
 * El relleno al crear y la relación del trait (`REQ-ORG-002` RB-2).
 *
 * Que el trait rellene `organization_id` no es una comodidad: es lo que hace
 * **imposible** equivocarse. Si el código de aplicación pudiera asignarlo,
 * tarde o temprano alguien lo asignaría mal, y el error sería silencioso
 * porque la fila quedaría guardada en la organización equivocada sin que nada
 * lanzase.
 */
final class BelongsToOrganizationTest extends TenantTestCase
{
    public function test_creating_fills_the_organization_from_the_context(): void
    {
        $organization = Organization::factory()->create();

        $record = $this->withinOrganization(
            $organization,
            fn (): TenantTestRecord => TenantTestRecord::create(['name' => 'propio']),
        );

        $this->assertSame((int) $organization->getKey(), $record->organization_id);
    }

    /**
     * El código de aplicación no escribe la columna: no está en `$fillable`,
     * así que ni siquiera por asignación masiva puede colarse.
     */
    public function test_the_tenant_column_is_not_mass_assignable(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $record = $this->withinOrganization($organization, fn (): TenantTestRecord => TenantTestRecord::create([
            'name' => 'propio',
            'organization_id' => $neighbour->getKey(),
        ]));

        $this->assertSame((int) $organization->getKey(), $record->organization_id);
    }

    /**
     * Asignarla a mano a otra organización lanza, en lugar de guardar una fila
     * que ninguna consulta filtrada volvería a ver del todo (RB-7).
     */
    public function test_assigning_another_organization_by_hand_throws(): void
    {
        $organization = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($organization);

        $record = new TenantTestRecord(['name' => 'ajeno']);
        $record->organization_id = (int) $neighbour->getKey();

        $this->expectException(CrossTenantRelationException::class);

        $record->save();
    }

    /**
     * Asignar la organización activa es redundante, pero no es un error.
     */
    public function test_assigning_the_active_organization_by_hand_is_allowed(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsOrganization($organization);

        $record = new TenantTestRecord(['name' => 'propio']);
        $record->organization_id = (int) $organization->getKey();
        $record->save();

        $this->assertSame((int) $organization->getKey(), $record->fresh()?->organization_id);
    }

    /**
     * SP-3: crear también es tocar una tabla del tenant.
     */
    public function test_creating_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        TenantTestRecord::create(['name' => 'huérfano']);
    }

    public function test_it_relates_back_to_its_organization(): void
    {
        $organization = Organization::factory()->create();

        $record = $this->withinOrganization(
            $organization,
            fn (): TenantTestRecord => TenantTestRecord::create(['name' => 'propio']),
        );

        $this->assertTrue($record->organization->is($organization));
    }

    /**
     * El trait declara el scope una sola vez por clase, y con el nombre que
     * `scopeWithoutTenantScope()` usa para quitarlo. Si divergieran, el scope
     * seguiría aplicándose y `withoutTenantScope()` no haría nada, en silencio.
     */
    public function test_the_model_registers_the_tenant_scope(): void
    {
        $this->assertArrayHasKey(TenantScope::class, (new TenantTestRecord)->getGlobalScopes());
    }

    public function test_the_tenant_column_is_the_one_the_scope_uses(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsOrganization($organization);

        $sql = TenantTestRecord::query()->toSql();

        $this->assertStringContainsString('organization_id', $sql);
    }
}
