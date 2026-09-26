<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\UsageCounter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El apartado y su máquina de estados, contra la organización vecina
 * (`REQ-QAX-008` RB-2, `REQ-ORG-002`).
 *
 * `LayawayStateMachine::transition()` busca y bloquea la fila por el global
 * scope. Lo que se afirma es que, con el tenant de un negocio fijado, el
 * apartado del otro **no existe**: no se transiciona, no se bloquea, y el cupo
 * que se toca es siempre el de la organización fijada.
 */
final class LayawayIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_a_layaway_of_another_organization_cannot_be_transitioned(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $neighbours = $this->layawayIn($theirs, LayawayStatus::Active);
        $this->withinOrganization($theirs, fn () => UsageCounter::factory()->forMetric(UsageMetric::ActiveLayaways, 7)->create());

        try {
            $this->withinOrganization($ours, fn () => app(LayawayStateMachine::class)->transition($neighbours, LayawayStatus::Cancelled));
            $this->fail('Se transicionó el apartado de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('active', DB::table('layaways')->where('id', $neighbours->getKey())->value('status'));
        $this->assertNull(DB::table('layaways')->where('id', $neighbours->getKey())->value('cancelled_at'));
        $this->assertSame(7, (int) DB::table('usage_counters')
            ->where('organization_id', $theirs->getKey())
            ->where('metric', UsageMetric::ActiveLayaways->value)
            ->value('value'));
    }

    public function test_the_layaways_of_the_neighbour_are_invisible(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $mine = $this->layawayIn($ours);
        $this->layawayIn($theirs);

        $visible = $this->withinOrganization($ours, fn () => Layaway::query()->pluck('id')->all());

        $this->assertSame([$mine->getKey()], $visible);
    }
}
