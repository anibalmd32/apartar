<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DashboardReceiptsIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    public function test_the_neighbour_receipts_do_not_reach_our_dashboard(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
        $ours = $this->receiptFor($this->layawayIn($organization, LayawayStatus::Active));

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $this->receiptFor($this->layawayIn($neighbour, LayawayStatus::Active), attributes: ['created_at' => CarbonImmutable::now()->subDays(10)]);
        $this->withoutOrganization();

        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pendingReceipts', 1)
            ->where('pendingReceipts.0.id', $ours->getKey()));
    }
}
