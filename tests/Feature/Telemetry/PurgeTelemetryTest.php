<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Console\Commands\PurgeTelemetry;
use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Telemetry\TelemetryRetention;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PurgeTelemetryTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $free;

    private Organization $pro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-22 03:45:00');

        $this->free = $this->organizationOn(30);
        $this->pro = $this->organizationOn(180);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_each_organization_keeps_what_its_plan_allows(): void
    {
        foreach ([$this->free, $this->pro, null] as $organization) {
            foreach ([10, 45, 200, 400] as $daysAgo) {
                $this->event($organization, $daysAgo);
            }
        }

        $this->artisan('telemetry:purge')->assertSuccessful();

        $this->assertSame([10], $this->remainingAges($this->free));
        $this->assertSame([10, 45], $this->remainingAges($this->pro));
        $this->assertSame([10, 45, 200], $this->remainingAges(null));
        $this->assertSame(TelemetryRetention::PLATFORM_DEFAULT_DAYS, 365);
    }

    public function test_aggregates_are_preserved_and_accumulated(): void
    {
        $this->event($this->free, 40, 'catalog_viewed');
        $this->event($this->free, 40, 'catalog_viewed');
        $this->event($this->free, 41, 'layaway_created');

        $this->artisan('telemetry:purge')->assertSuccessful();

        $this->event($this->free, 40, 'catalog_viewed');
        $this->artisan('telemetry:purge')->assertSuccessful();

        $rows = DB::table('telemetry_monthly_aggregates')->orderBy('event_name')->get(['organization_id', 'event_name', 'year_month', 'count']);

        $this->assertCount(2, $rows);
        $this->assertSame(['catalog_viewed', '2026-09', 3], [$rows[0]->event_name, $rows[0]->year_month, (int) $rows[0]->count]);
        $this->assertSame(['layaway_created', '2026-09', 1], [$rows[1]->event_name, $rows[1]->year_month, (int) $rows[1]->count]);
        $this->assertSame((int) $this->free->getKey(), (int) $rows[0]->organization_id);
        $this->assertSame(0, DB::table('telemetry_events')->count());
    }

    public function test_a_capped_run_resumes_without_double_counting(): void
    {
        foreach (range(1, 5) as $_) {
            $this->event($this->free, 60);
        }

        $this->artisan('telemetry:purge', ['--max' => 3])->assertSuccessful();
        $this->assertSame(2, DB::table('telemetry_events')->count());
        $this->assertSame(3, (int) DB::table('telemetry_monthly_aggregates')->sum('count'));

        $this->artisan('telemetry:purge')->assertSuccessful();
        $this->assertSame(0, DB::table('telemetry_events')->count());
        $this->assertSame(5, (int) DB::table('telemetry_monthly_aggregates')->sum('count'));
        $this->assertSame(100_000, PurgeTelemetry::MAX_ROWS);
    }

    public function test_a_downgrade_shortens_retention_on_the_next_run(): void
    {
        $this->event($this->pro, 100);
        $this->artisan('telemetry:purge')->assertSuccessful();
        $this->assertSame([100], $this->remainingAges($this->pro));

        $free = Plan::factory()->create();
        $this->withinOrganization($this->pro, fn () => Subscription::query()->update(['plan_id' => $free->getKey()]));
        $this->withoutOrganization();

        $this->artisan('telemetry:purge')->assertSuccessful();
        $this->assertSame([], $this->remainingAges($this->pro));
        $this->assertSame(1, (int) DB::table('telemetry_monthly_aggregates')->where('organization_id', $this->pro->getKey())->sum('count'));
    }

    private function organizationOn(int $days): Organization
    {
        $organization = Organization::factory()->create();
        $plan = Plan::factory()->create(['features' => PlanFeature::noneEnabled() + [PlanAllowance::TelemetryRetentionDays->value => $days]]);
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
        $this->withoutOrganization();

        return $organization;
    }

    private function event(?Organization $organization, int $daysAgo, string $name = 'catalog_viewed'): void
    {
        DB::table('telemetry_events')->insert([
            'organization_id' => $organization?->getKey(),
            'event_name' => $name,
            'visitor_id' => '0190f0f0-0000-7000-8000-000000000001',
            'payload' => '{}',
            'session_id' => 'sesion-abc-123',
            'ip_hash' => str_repeat('a', 64),
            'occurred_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * @return list<int>
     */
    private function remainingAges(?Organization $organization): array
    {
        return DB::table('telemetry_events')
            ->when($organization === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organization?->getKey()))
            ->orderByDesc('occurred_at')
            ->pluck('occurred_at')
            ->map(fn (string $at): int => (int) round(CarbonImmutable::parse($at)->diffInDays(now(), true)))
            ->all();
    }
}
