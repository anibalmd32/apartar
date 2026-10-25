<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ApplyScheduledDowngrades;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ApplyScheduledDowngradesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-20 12:00:00');
        $this->free = Plan::factory()->create(['code' => 'free', 'position' => 1, 'max_catalogs' => 1]);
        $this->pro = Plan::factory()->unlimited()->create(['code' => 'pro', 'position' => 2]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_due_downgrades_are_applied_each_in_its_organization(): void
    {
        $due = $this->organizationDowngrading('2026-10-19 00:00:00');
        $later = $this->organizationDowngrading('2026-10-21 00:00:00');

        $this->assertSame(['applied' => 1, 'unpublished' => 0], $this->runJob());

        $this->assertTrue($this->planOf($due)->is($this->free));
        $this->assertTrue($this->planOf($later)->is($this->pro));
        $this->assertSame(['applied' => 0, 'unpublished' => 0], $this->runJob());
    }

    public function test_the_catalog_grace_is_picked_up_seven_days_later(): void
    {
        $organization = $this->organizationDowngrading('2026-10-19 00:00:00');
        $this->withinOrganization($organization, fn () => Catalog::factory()->count(3)->published()->create());
        $this->recalculateUsage($organization);
        $this->runJob();

        CarbonImmutable::setTestNow('2026-10-27 11:59:00');
        $this->assertSame(['applied' => 0, 'unpublished' => 0], $this->runJob());

        CarbonImmutable::setTestNow('2026-10-27 12:00:00');
        $this->assertSame(['applied' => 0, 'unpublished' => 2], $this->runJob());
    }

    public function test_a_failing_organization_does_not_stop_the_pass(): void
    {
        $broken = $this->organizationDowngrading('2026-10-19 00:00:00');
        $healthy = $this->organizationDowngrading('2026-10-19 00:00:00');
        $brokenId = $this->withinOrganization($broken, fn (): int => (int) Subscription::query()->value('id'));

        DB::listen(function (QueryExecuted $query) use ($brokenId): void {
            if (str_starts_with($query->sql, 'update `subscriptions`') && end($query->bindings) === $brokenId) {
                throw new RuntimeException('falla al escribir');
            }
        });

        $this->assertSame(1, $this->runJob()['applied']);
        $this->assertTrue($this->planOf($broken)->is($this->pro));
        $this->assertTrue($this->planOf($healthy)->is($this->free));
    }

    public function test_it_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'subscriptions:apply-downgrades');

        $this->assertNotNull($event);
        $this->assertSame('5 * * * *', $event->expression);
        $this->assertSame('default', (new ApplyScheduledDowngrades)->queue);
    }

    /**
     * @return array{applied: int, unpublished: int}
     */
    private function runJob(): array
    {
        $this->withoutOrganization();

        return app()->call([new ApplyScheduledDowngrades, 'handle']);
    }

    private function organizationDowngrading(string $at): Organization
    {
        $organization = Organization::factory()->create();
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($this->pro)->create([
            'scheduled_plan_id' => $this->free->getKey(),
            'scheduled_plan_at' => $at,
        ]));

        return $organization;
    }

    private function planOf(Organization $organization): Plan
    {
        return $this->withinOrganization($organization, fn (): Plan => Subscription::query()->firstOrFail()->plan);
    }
}
