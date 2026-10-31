<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Layaway\ReconciliationReport;
use App\Models\ExchangeRate;
use App\Queries\Platform\SystemHealthQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SystemHealthTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_a_task_is_overdue_at_twice_its_interval(): void
    {
        $now = CarbonImmutable::parse('2026-10-22 12:10:00');

        $this->assertFalse(SystemHealthQuery::isOverdue('0 * * * *', $now->subMinutes(70), $now));
        $this->assertTrue(SystemHealthQuery::isOverdue('0 * * * *', $now->subMinutes(121), $now));
        $this->assertFalse(SystemHealthQuery::isOverdue('30 5 * * *', $now->subHours(30), $now));
        $this->assertTrue(SystemHealthQuery::isOverdue('30 5 * * *', $now->subHours(49), $now));
        $this->assertTrue(SystemHealthQuery::isOverdue('0 * * * *', null, $now));
    }

    public function test_the_frequency_reads_as_words(): void
    {
        $now = CarbonImmutable::parse('2026-10-22 12:10:00');

        $this->assertSame('Cada 30 min', SystemHealthQuery::frequency('*/30 * * * *', $now));
        $this->assertSame('Cada hora', SystemHealthQuery::frequency('0 * * * *', $now));
        $this->assertSame('Cada día a las 05:30', SystemHealthQuery::frequency('30 5 * * *', $now));
        $this->assertSame('Cada semana', SystemHealthQuery::frequency('30 3 * * 0', $now));
    }

    public function test_the_declared_schedule_matches_the_real_one(): void
    {
        Artisan::call('schedule:list');

        $real = [];

        foreach (app(Schedule::class)->events() as $event) {
            $real[(string) $event->description] = $event->expression;
        }

        ksort($real);
        $declared = SystemHealthQuery::SCHEDULE;
        ksort($declared);

        $this->assertSame($declared, $real);
    }

    public function test_each_signal_with_seeded_data(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 12:00:00');
        Cache::forever(ReconciliationReport::CACHE_KEY, ['discrepancies' => 3, 'stock' => 1, 'unresolved' => 1, 'lastRunAt' => '2026-10-22T05:30:00Z']);
        ExchangeRate::factory()->create(['source' => 'bcv', 'published_at' => now()->subHours(30)]);
        config(['broadcasting.connections.reverb.options.host' => '127.0.0.1', 'broadcasting.connections.reverb.options.port' => 1]);

        foreach (array_keys(SystemHealthQuery::SCHEDULE) as $name) {
            event(new ScheduledTaskFinished(app(Schedule::class)->call(fn () => null)->name($name), 0.1));
        }

        $health = app(SystemHealthQuery::class)->compute();

        $this->assertSame(3, $health['reconciliation']['discrepancies']);
        $this->assertSame(['source' => 'bcv', 'ageHours' => 30, 'isStale' => true], $health['exchangeRate']);
        $this->assertFalse($health['realtime']['connected']);
        $this->assertSame([false], array_values(array_unique(array_column($health['scheduledJobs'], 'isOverdue'))));
        $this->assertSame(0, $health['queue']['failed']);

        CarbonImmutable::setTestNow();
    }
}
