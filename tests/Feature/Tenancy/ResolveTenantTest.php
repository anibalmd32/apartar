<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantSurface;
use App\Http\Middleware\DeclaresTenantSurface;
use App\Http\Middleware\OperatesWithoutTenant;
use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * `ResolveTenant` y la guarda de la superficie de plataforma
 * (`REQ-ORG-002` RB-4 y SP-1).
 *
 * Hasta ahora el tenant se fijaba a mano en los tests. Estos son los primeros
 * que lo dejan en manos de la infraestructura, que es como va a ocurrir en
 * producción: la sesión trae al usuario, del usuario sale la organización, y
 * el controlador no escribe un solo `where`.
 *
 * Las rutas son de prueba y se definen aquí: las de negocio llegan con su
 * hito, y montar el middleware sobre rutas inventadas es lo que permite
 * probarlo hoy sin adelantar ninguna.
 */
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

    /**
     * RB-4: en el backoffice el tenant sale de `user->organization_id`.
     */
    public function test_it_fixes_the_tenant_from_the_session(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->actingAs($user)
            ->get('/_test/backoffice/equipo')
            ->assertOk()
            ->assertJsonPath('organization', (int) $organization->getKey());
    }

    /**
     * La afirmación completa, con dos organizaciones: desde la sesión de una
     * no se ve ni un usuario de la otra, y el controlador no filtró nada.
     */
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

    /**
     * Y la simétrica: la vecina ve lo suyo y solo lo suyo.
     */
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

    /**
     * SP-1: sesión sin tenant resoluble → cierre de sesión y redirección al
     * acceso. Lo que no puede pasar es que la petición siga adelante.
     */
    public function test_an_unresolvable_session_is_logged_out(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        // La organización desaparece por debajo: es el caso del negocio
        // eliminado con la sesión de su equipo todavía abierta.
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

    /**
     * RB-4, fila de plataforma: opera **sin** tenant, y consulta con
     * `withoutTenantScope()` para ver a todas las organizaciones.
     */
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

    /**
     * La guarda es afirmativa: si la ruta llegase con un tenant fijado —dos
     * grupos de rutas solapados— se lanza, en vez de dejar pasar una petición
     * de plataforma con media visión del sistema.
     */
    public function test_the_platform_guard_rejects_a_request_that_already_has_a_tenant(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsOrganization($organization);

        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);

        $this->get('/_test/plataforma/salud');
    }

    /**
     * Lo que `T-007` va a preguntar para derivar sus casos de la tabla de
     * rutas: qué superficie gobierna cada middleware.
     */
    public function test_each_middleware_declares_its_surface(): void
    {
        $this->assertSame(TenantSurface::Backoffice, ResolveTenant::surface());
        $this->assertSame(TenantSurface::Platform, OperatesWithoutTenant::surface());

        $this->assertInstanceOf(DeclaresTenantSurface::class, app(ResolveTenant::class));
        $this->assertInstanceOf(DeclaresTenantSurface::class, app(OperatesWithoutTenant::class));
    }

    /**
     * Los alias con los que se enrutan las superficies.
     */
    public function test_the_middlewares_are_registered_with_their_aliases(): void
    {
        $aliases = $this->app->make(Kernel::class)->getMiddlewareAliases();

        $this->assertSame(ResolveTenant::class, $aliases['tenant'] ?? null);
        $this->assertSame(OperatesWithoutTenant::class, $aliases['without-tenant'] ?? null);
    }
}
