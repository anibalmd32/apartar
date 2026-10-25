<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class PortalAlertsIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_overdue_installments_of_the_same_person_in_another_business_raise_no_alert(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $theirs = $this->subscribe($this->neighbourOrganization());
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($theirs, fn () => app(ActivateLayawayAction::class)->handle($neighbours));
        $this->withoutOrganization();
        DB::table('customers')->update(['document_number' => 'V12345678']);

        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($mine->customer_id));
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer');
        CarbonImmutable::setTestNow('2026-10-12 12:00:00');

        $this->get(route('portal.dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alerts', [])
            ->where('businesses.0.totals.overdueInstallments', 0));
    }
}
