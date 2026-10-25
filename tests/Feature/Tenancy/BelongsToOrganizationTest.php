<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Domain\Tenancy\TenantScope;
use App\Models\Organization;
use Tests\Support\TenantTestCase;
use Tests\Support\TenantTestRecord;

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

    public function test_assigning_the_active_organization_by_hand_is_allowed(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsOrganization($organization);

        $record = new TenantTestRecord(['name' => 'propio']);
        $record->organization_id = (int) $organization->getKey();
        $record->save();

        $this->assertSame((int) $organization->getKey(), $record->fresh()?->organization_id);
    }

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
