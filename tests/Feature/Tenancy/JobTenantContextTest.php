<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\MissingTenantContextException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Jobs\CountsUsersJob;
use Tests\Support\Jobs\FailsWithinTenantJob;
use Tests\Support\Jobs\ForgetfulJob;
use Tests\Support\Jobs\VisitsEveryOrganizationJob;
use Tests\TestCase;

/**
 * El tenant de un job (`REQ-ORG-002` RB-5 y SP-7, `REQ-INF-003` RB-1b).
 *
 * Los jobs de prueba viven en `tests/Support/Jobs` porque los reales llegan
 * cada uno con su tarea; lo que se prueba aquí es el mecanismo, no ninguno de
 * ellos.
 */
final class JobTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_receives_its_organization_in_the_payload(): void
    {
        $organization = Organization::factory()->create();

        $this->withinOrganization($organization, fn () => User::factory()->count(2)->create());

        $job = new CountsUsersJob((int) $organization->getKey());
        $job->handle();

        $this->assertSame(2, $job->seen);
    }

    /**
     * RB-5: el job no hereda el tenant del proceso que lo encoló. Aunque el
     * contexto traiga otra organización, el job trabaja sobre la de su
     * payload.
     */
    public function test_a_job_does_not_inherit_the_tenant_of_whoever_queued_it(): void
    {
        $queuer = Organization::factory()->create();
        $target = $this->neighbourOrganization();

        $this->withinOrganization($queuer, fn () => User::factory()->count(3)->create());
        $this->withinOrganization($target, fn () => User::factory()->create());

        $this->actingAsOrganization($queuer);

        $job = new CountsUsersJob((int) $target->getKey());
        $job->handle();

        $this->assertSame(1, $job->seen);
    }

    /**
     * SP-7, y es la fuga más difícil de detectar del diseño: una cola
     * compartida procesa dos organizaciones seguidas.
     *
     * El mecanismo del test: se ejecutan dos jobs **en el mismo proceso y
     * contra el mismo `TenantContext` singleton**, sin tocar el contexto entre
     * medias. Si el primero no limpiara, el segundo arrancaría con el tenant
     * puesto y `setOrganization()` lanzaría `TenantAlreadySetException` —el
     * trait hace `forget()` antes de fijar, así que ni siquiera eso avisaría—
     * y acabaría contando los usuarios del primero. Se comprueban las dos
     * cosas: lo que cada job ve, y que el contexto queda vacío entre ambos.
     */
    public function test_the_tenant_is_cleared_between_two_jobs(): void
    {
        $first = Organization::factory()->create();
        $second = $this->neighbourOrganization();

        $this->withinOrganization($first, fn () => User::factory()->count(3)->create());
        $this->withinOrganization($second, fn () => User::factory()->create());

        $this->withoutOrganization();
        $context = app(TenantContext::class);

        $one = new CountsUsersJob((int) $first->getKey());
        $one->handle();

        $this->assertFalse($context->hasOrganization(), 'El primer job dejó el tenant puesto.');

        $two = new CountsUsersJob((int) $second->getKey());
        $two->handle();

        $this->assertFalse($context->hasOrganization(), 'El segundo job dejó el tenant puesto.');

        $this->assertSame(3, $one->seen);
        $this->assertSame(1, $two->seen, 'El segundo job vio los usuarios del primero.');
    }

    /**
     * La mitad que de verdad importa del `finally`: el job que **lanza**
     * también limpia. Sin esto, un fallo en el job de una organización
     * contaminaría al siguiente, y el segundo fallo no se parecería en nada al
     * primero.
     */
    public function test_the_tenant_is_cleared_when_the_job_throws(): void
    {
        $organization = Organization::factory()->create();

        $this->withoutOrganization();
        $context = app(TenantContext::class);

        try {
            (new FailsWithinTenantJob((int) $organization->getKey()))->handle();
            $this->fail('Se esperaba que el job lanzara.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo a mitad del job', $exception->getMessage());
        }

        $this->assertFalse($context->hasOrganization());
    }

    public function test_a_failed_job_does_not_contaminate_the_next_one(): void
    {
        $failing = Organization::factory()->create();
        $next = $this->neighbourOrganization();

        $this->withinOrganization($failing, fn () => User::factory()->count(4)->create());
        $this->withinOrganization($next, fn () => User::factory()->create());

        $this->withoutOrganization();

        try {
            (new FailsWithinTenantJob((int) $failing->getKey()))->handle();
        } catch (RuntimeException) {
            // El fallo es el escenario; lo que se mira es el job siguiente.
        }

        $job = new CountsUsersJob((int) $next->getKey());
        $job->handle();

        $this->assertSame(1, $job->seen);
    }

    /**
     * SP-3: un job que se olvidó de fijar su tenant y toca una tabla del
     * tenant **falla**. Es preferible un job caído a una fuga.
     */
    public function test_a_job_without_a_tenant_fails_instead_of_querying_unfiltered(): void
    {
        $organization = Organization::factory()->create();
        $this->withinOrganization($organization, fn () => User::factory()->create());

        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        (new ForgetfulJob)->handle();
    }

    /**
     * Si el payload trae una organización que ya no existe, el job falla al
     * resolverla y no llega a tocar nada.
     */
    public function test_a_job_with_an_unknown_organization_fails_to_resolve(): void
    {
        $this->withoutOrganization();

        $this->expectException(ModelNotFoundException::class);

        (new CountsUsersJob(9_999))->handle();
    }

    public function test_an_unresolvable_organization_leaves_the_context_untouched(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsOrganization($organization);

        try {
            (new CountsUsersJob(9_999))->handle();
        } catch (ModelNotFoundException) {
            // Lo que se mira es el estado que deja.
        }

        $this->assertSame((int) $organization->getKey(), app(TenantContext::class)->organizationId());
    }

    /**
     * `REQ-INF-003` RB-1b: el otro mecanismo. Un job que recorre todas las
     * organizaciones fija el tenant **por elemento**, nunca uno para toda la
     * ejecución.
     */
    public function test_a_job_across_organizations_fixes_the_tenant_per_element(): void
    {
        $first = Organization::factory()->create();
        $second = $this->neighbourOrganization();

        $this->withinOrganization($first, fn () => User::factory()->count(2)->create());
        $this->withinOrganization($second, fn () => User::factory()->count(5)->create());

        $this->withoutOrganization();

        $job = new VisitsEveryOrganizationJob;
        $job->handle();

        $this->assertSame([
            (int) $first->getKey() => 2,
            (int) $second->getKey() => 5,
        ], $job->usersPerOrganization);
    }

    public function test_a_job_across_organizations_leaves_the_context_clean(): void
    {
        Organization::factory()->count(3)->create();

        $this->withoutOrganization();

        (new VisitsEveryOrganizationJob)->handle();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    /**
     * Y restaura lo que hubiera antes, que es lo que permite invocarlo desde
     * un comando que ya tenía contexto.
     */
    public function test_a_job_across_organizations_restores_the_previous_tenant(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->actingAsOrganization($organization);

        (new VisitsEveryOrganizationJob)->handle();

        $this->assertSame((int) $organization->getKey(), app(TenantContext::class)->organizationId());
    }
}
