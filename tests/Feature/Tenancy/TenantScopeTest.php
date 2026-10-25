<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Organization;
use Tests\Support\TenantTestCase;
use Tests\Support\TenantTestRecord;

final class TenantScopeTest extends TenantTestCase
{
    public function test_a_query_filters_by_the_active_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => TenantTestRecord::create(['name' => 'propio']));
        $this->withinOrganization($neighbour, fn () => TenantTestRecord::create(['name' => 'ajeno']));

        $this->actingAsOrganization($own);

        $names = TenantTestRecord::query()->pluck('name')->all();

        $this->assertSame(['propio'], $names);
    }

    public function test_the_neighbour_cannot_see_our_rows(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => TenantTestRecord::create(['name' => 'propio']));

        $visible = $this->withinOrganization(
            $neighbour,
            fn (): int => TenantTestRecord::query()->count(),
        );

        $this->assertSame(0, $visible);
        $this->assertTrue($this->hasNeighbourOrganization());
    }

    public function test_a_row_of_another_organization_is_not_found_by_id(): void
    {
        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): TenantTestRecord => TenantTestRecord::create(['name' => 'ajeno']),
        );

        $this->actingAsOrganization(Organization::factory()->create());

        $this->assertNull(TenantTestRecord::query()->find($foreign->getKey()));
    }

    public function test_querying_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        TenantTestRecord::query()->get();
    }

    public function test_the_exception_names_the_model_and_the_way_out(): void
    {
        $this->withoutOrganization();

        try {
            TenantTestRecord::query()->count();
            $this->fail('Se esperaba MissingTenantContextException.');
        } catch (MissingTenantContextException $exception) {
            $this->assertStringContainsString(TenantTestRecord::class, $exception->getMessage());
            $this->assertStringContainsString('withoutTenantScope', $exception->getMessage());
        }
    }

    public function test_without_tenant_scope_returns_every_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => TenantTestRecord::create(['name' => 'propio']));
        $this->withinOrganization($neighbour, fn () => TenantTestRecord::create(['name' => 'ajeno']));

        $this->actingAsOrganization($own);

        $names = TenantTestRecord::withoutTenantScope()->orderBy('name')->pluck('name')->all();

        $this->assertSame(['ajeno', 'propio'], $names);
    }

    public function test_without_tenant_scope_works_with_no_tenant_at_all(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn () => TenantTestRecord::create(['name' => 'propio']));

        $this->withoutOrganization();

        $this->assertSame(1, TenantTestRecord::withoutTenantScope()->count());
    }

    public function test_two_organizations_may_reuse_the_same_business_key(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => TenantTestRecord::create(['name' => 'repetido']));
        $this->withinOrganization($neighbour, fn () => TenantTestRecord::create(['name' => 'repetido']));

        $this->assertSame(2, TenantTestRecord::withoutTenantScope()->count());
    }
}
