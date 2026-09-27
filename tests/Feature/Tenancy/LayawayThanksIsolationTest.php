<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Controllers\Catalog\LayawayController;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La página de gracias contra la organización vecina (`REQ-LAY-001` RB-10,
 * `REQ-QAX-008` RB-2).
 *
 * El código es único en toda la plataforma, y la página se resuelve con el
 * tenant del **catálogo de la URL**. Aunque la sesión tenga guardado el código
 * de un apartado de otro negocio —porque ese cliente también apartó allí—,
 * bajo este catálogo ese apartado no existe: ni se enseña su producto ni sus
 * cuentas de cobro.
 */
final class LayawayThanksIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

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
