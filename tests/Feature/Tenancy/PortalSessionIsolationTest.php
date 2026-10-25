<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalSessionIsolationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_the_code_of_one_business_opens_only_that_business(): void
    {
        $ours = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $theirs = $this->neighbourOrganization(['name' => 'Mueblería Centro']);

        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        DB::table('customers')->update(['document_number' => 'V12345678']);

        $this->post(route('portal.access.code'), ['document' => 'V-12.345.678', 'code' => $mine->code])
            ->assertRedirect(route('portal.dashboard'));

        $this->get(route('portal.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('businesses.0.business.name', 'Ferretería Oriente')
            ->has('businesses', 1)
            ->has('businesses.0.layaways', 1)
            ->where('businesses.0.layaways.0.code', $mine->code));

        $this->assertSame((int) $ours->getKey(), app(TenantContext::class)->organizationId());
        $this->assertNotSame($mine->code, $neighbours->code);
    }

    public function test_a_layaway_code_resolves_its_own_organization_and_nothing_else(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        $this->withoutOrganization();

        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->forLayawayCode($mine->code)->is($ours));
        $this->assertTrue($resolver->forLayawayCode($neighbours->code)->is($theirs));

        $this->expectException(ModelNotFoundException::class);
        $resolver->forLayawayCode('AP-ZZZZZZZZ');
    }
}
