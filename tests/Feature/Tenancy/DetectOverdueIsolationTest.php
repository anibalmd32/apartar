<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La detección de mora contra la organización vecina
 * (`REQ-QAX-008` RB-2, `REQ-ORG-002`).
 *
 * Con el tenant de un negocio, las cuotas vencidas del otro no existen: ni
 * entran en mora ni arrastran a su apartado, y la gracia y la zona que se
 * aplican son siempre las de la organización fijada.
 */
final class DetectOverdueIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_detecting_one_organization_leaves_the_neighbour_untouched(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $mine = $this->activeLayaway($ours);
        $neighbours = $this->activeLayaway($theirs);

        $summary = $this->withinOrganization($ours, fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-10-05 06:00:00')));

        $this->assertSame(1, $summary->layawaysBecameOverdue);
        $this->assertSame('overdue', DB::table('layaways')->where('id', $mine->getKey())->value('status'));
        $this->assertSame('active', DB::table('layaways')->where('id', $neighbours->getKey())->value('status'));
        $this->assertSame(['pending'], DB::table('installments')->where('layaway_id', $neighbours->getKey())->distinct()->pluck('status')->all());
    }

    private function activeLayaway(Organization $organization): Layaway
    {
        $layaway = $this->layawayIn($organization);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        CarbonImmutable::setTestNow();

        return $layaway;
    }
}
