<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantSurface;
use App\Http\Middleware\DeclaresTenantSurface;
use App\Http\Middleware\OperatesWithoutTenant;
use App\Http\Middleware\ResolveCatalogTenant;
use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

final class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'tenant'])->get('/_test/backoffice/equipo', function (): array {
            return [
                'organization' => app(TenantContext::class)->organizationId(),
                'users' => User::query()->pluck('name')->all(),
            ];
        });

        Route::middleware(['web', 'without-tenant'])->get('/_test/plataforma/salud', function (): array {
            return [
                'tenant' => app(TenantContext::class)->hasOrganization(),
                'users' => User::withoutTenantScope()->count(),
            ];
        });
    }

    public function test_it_fixes_the_tenant_from_the_session(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->actingAs($user)
            ->get('/_test/backoffice/equipo')
            ->assertOk()
            ->assertJsonPath('organization', (int) $organization->getKey());
    }

    public function test_the_request_only_sees_its_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $own,
            fn (): User => User::factory()->create(['name' => 'Propio']),
        );

        $this->withinOrganization($neighbour, fn () => User::factory()->create(['name' => 'Ajeno']));

        $this->actingAs($user)
            ->get('/_test/backoffice/equipo')
            ->assertOk()
            ->assertJsonPath('users', ['Propio']);
    }

    public function test_each_session_sees_its_own_side(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create(['name' => 'Propio']));

        $theirs = $this->withinOrganization(
            $neighbour,
            fn (): User => User::factory()->create(['name' => 'Ajeno']),
        );

        $this->actingAs($theirs)
            ->get('/_test/backoffice/equipo')
            ->assertOk()
            ->assertJsonPath('users', ['Ajeno']);
    }

    public function test_an_unresolvable_session_is_logged_out(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $organization->forceDelete();

        $this->actingAs($user)
            ->get('/_test/backoffice/equipo')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_session_without_user_never_reaches_the_tenant_query(): void
    {
        $this->get('/_test/backoffice/equipo')->assertRedirect(route('login'));

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    public function test_the_platform_surface_operates_without_a_tenant(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create());
        $this->withinOrganization($neighbour, fn () => User::factory()->create());

        $this->get('/_test/plataforma/salud')
            ->assertOk()
            ->assertJsonPath('tenant', false)
            ->assertJsonPath('users', 2);
    }

    public function test_the_platform_guard_rejects_a_request_that_already_has_a_tenant(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsOrganization($organization);

        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);

        $this->get('/_test/plataforma/salud');
    }

    public function test_each_middleware_declares_its_surface(): void
    {
        $this->assertSame(TenantSurface::Backoffice, ResolveTenant::surface());
        $this->assertSame(TenantSurface::Platform, OperatesWithoutTenant::surface());
        $this->assertSame(TenantSurface::PublicCatalog, ResolveCatalogTenant::surface());
        $this->assertInstanceOf(DeclaresTenantSurface::class, app(ResolveCatalogTenant::class));

        $this->assertInstanceOf(DeclaresTenantSurface::class, app(ResolveTenant::class));
        $this->assertInstanceOf(DeclaresTenantSurface::class, app(OperatesWithoutTenant::class));
    }

    public function test_the_middlewares_are_registered_with_their_aliases(): void
    {
        $aliases = $this->app->make(Kernel::class)->getMiddlewareAliases();

        $this->assertSame(ResolveTenant::class, $aliases['tenant'] ?? null);
        $this->assertSame(OperatesWithoutTenant::class, $aliases['without-tenant'] ?? null);
        $this->assertSame(ResolveCatalogTenant::class, $aliases['catalog-tenant'] ?? null);
    }
}
