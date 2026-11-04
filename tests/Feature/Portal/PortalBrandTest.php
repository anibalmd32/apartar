<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalBrandTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_a_portal_tied_to_one_business_carries_that_business_identity(): void
    {
        $organization = $this->subscribe(Organization::factory()->create(['name' => 'Moda Luna', 'brand_color' => '#3a0ca3']));
        $layaway = $this->layawayIn($organization);
        $customer = $this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')->get(route('portal.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.portal.businessName', 'Moda Luna')
            ->where('auth.portal.business.name', 'Moda Luna')
            ->where('auth.portal.business.brandColor', '#3a0ca3')
            ->has('auth.portal.business.brandingEnabled'));
    }
}
