<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalLayawayIsolationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_the_layaway_of_the_same_person_in_another_business_is_not_found(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $theirs = $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        DB::table('customers')->update(['document_number' => 'V12345678']);

        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($mine->customer_id));
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.layaways.show', ['code' => $mine->code]))->assertOk();
        $this->get(route('portal.layaways.show', ['code' => $neighbours->code]))->assertNotFound();
    }
}
