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

final class LayawayIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_a_layaway_of_another_organization_cannot_be_transitioned(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $neighbours = $this->layawayIn($theirs, LayawayStatus::Active);
        $this->withinOrganization($theirs, fn () => UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 7, 'computed_at' => now()]));

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
