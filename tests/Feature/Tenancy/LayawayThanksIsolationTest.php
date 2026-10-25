<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Controllers\Catalog\LayawayController;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayThanksIsolationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_a_code_of_another_organization_is_not_found_under_this_catalog(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $catalog = $this->withinOrganization($ours, function (): Catalog {
            Subscription::factory()->forPlan(Plan::factory()->create())->create();

            return Catalog::factory()->published()->create();
        });

        $neighbours = $this->layawayIn($theirs);
        $this->withoutOrganization();

        $this->withSession([LayawayController::SESSION_KEY => [$neighbours->code]])
            ->get("/c/{$catalog->slug}/apartar/{$neighbours->code}/gracias")
            ->assertNotFound();
    }
}
