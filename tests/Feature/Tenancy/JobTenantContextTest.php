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
        }

        $job = new CountsUsersJob((int) $next->getKey());
        $job->handle();

        $this->assertSame(1, $job->seen);
    }

    public function test_a_job_without_a_tenant_fails_instead_of_querying_unfiltered(): void
    {
        $organization = Organization::factory()->create();
        $this->withinOrganization($organization, fn () => User::factory()->create());

        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        (new ForgetfulJob)->handle();
    }

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
        }

        $this->assertSame((int) $organization->getKey(), app(TenantContext::class)->organizationId());
    }

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

    public function test_a_job_across_organizations_restores_the_previous_tenant(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->actingAsOrganization($organization);

        (new VisitsEveryOrganizationJob)->handle();

        $this->assertSame((int) $organization->getKey(), app(TenantContext::class)->organizationId());
    }
}
