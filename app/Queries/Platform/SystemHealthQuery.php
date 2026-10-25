<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Console\Commands\PurgeTelemetry;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Layaway\ReconciliationReport;
use Carbon\CarbonImmutable;
use Closure;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class SystemHealthQuery
{
    public const RATE_STALE_HOURS = 24;

    public const LAST_RUN_PREFIX = 'schedule:last:';

    public const QUEUES = ['high', 'default', 'exports', 'telemetry'];

    /**
     * @var array<string, string>
     */
    public const SCHEDULE = [
        'exchange-rate:fetch' => '*/30 * * * *',
        'layaways:expire' => '0 * * * *',
        'layaways:detect-overdue' => '0 * * * *',
        'layaways:reconcile' => '30 5 * * *',
        'subscriptions:recalculate-usage' => '0 2 * * *',
        'subscriptions:recalculate-usage:catalogs' => '15 * * * *',
        'subscriptions:issue-renewal-invoices' => '0 6 * * *',
        'subscriptions:apply-downgrades' => '5 * * * *',
        'subscriptions:process-dunning' => '30 6 * * *',
        'exports:purge-expired' => '0 3 * * *',
        'chat:recount' => '30 4 * * *',
        'push:purge-stale' => '30 3 * * 0',
        'notifications:dispatch-reminders' => '0 * * * *',
        'telemetry:purge' => '45 3 * * *',
    ];

    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $now = CarbonImmutable::now();

        return [
            'queue' => $this->isolated(fn (): array => $this->queue($now)),
            'exchangeRate' => $this->isolated(fn (): array => $this->exchangeRate($now)),
            'scheduledJobs' => $this->isolated(fn (): array => $this->scheduledJobs($now)),
            'storage' => $this->isolated(fn (): array => [
                'usedMb' => (int) DB::table('usage_counters')->where('metric', 'storage_mb')->sum('value'),
                'receiptsCount' => DB::table('receipts')->count(),
            ]),
            'realtime' => ['connected' => $this->reverbIsUp()],
            'reconciliation' => $this->isolated(function (): array {
                $last = ReconciliationReport::last();

                return [
                    'discrepancies' => $last['discrepancies'] ?? 0,
                    'unresolved' => $last['unresolved'] ?? 0,
                    'stock' => $last['stock'] ?? 0,
                    'lastRunAt' => $last['lastRunAt'] ?? null,
                    'command' => 'php artisan layaways:reconcile --fix',
                ];
            }),
            'telemetryPurge' => $this->isolated(fn (): ?array => Cache::get(PurgeTelemetry::LAST_RUN_KEY)),
        ];
    }

    public static function isOverdue(string $expression, ?CarbonImmutable $lastRunAt, CarbonImmutable $now): bool
    {
        if ($lastRunAt === null) {
            return true;
        }

        $cron = new CronExpression($expression);
        $previous = CarbonImmutable::instance($cron->getPreviousRunDate($now, 0, true));
        $before = CarbonImmutable::instance($cron->getPreviousRunDate($now, 1, true));
        $interval = max(60, $previous->diffInSeconds($before, true));

        return $lastRunAt->lessThan($now->subSeconds((int) (2 * $interval)));
    }

    /**
     * @return array{pending: int|null, byQueue: array<string, int|null>, failed: int, oldestPendingMinutes: int|null}
     */
    private function queue(CarbonImmutable $now): array
    {
        $byQueue = [];

        foreach (self::QUEUES as $name) {
            try {
                $byQueue[$name] = Queue::size($name);
            } catch (Throwable) {
                $byQueue[$name] = null;
            }
        }

        $known = array_filter($byQueue, fn (?int $size): bool => $size !== null);
        $oldest = config('queue.default') === 'database' ? DB::table('jobs')->min('available_at') : null;

        return [
            'pending' => $known === [] ? null : array_sum($known),
            'byQueue' => $byQueue,
            'failed' => DB::table('failed_jobs')->count(),
            'oldestPendingMinutes' => is_numeric($oldest) ? (int) floor(($now->getTimestamp() - (int) $oldest) / 60) : null,
        ];
    }

    /**
     * @return array{source: string, ageHours: int|null, isStale: bool}
     */
    private function exchangeRate(CarbonImmutable $now): array
    {
        $source = $this->rates->platformSource();
        $snapshot = $this->rates->forSource($source);
        $age = $snapshot === null ? null : (int) floor($snapshot->publishedAt->diffInHours($now, true));

        return ['source' => $source->value, 'ageHours' => $age, 'isStale' => $age === null || $age >= self::RATE_STALE_HOURS];
    }

    public static function frequency(string $expression, CarbonImmutable $now): string
    {
        $cron = new CronExpression($expression);
        $next = CarbonImmutable::instance($cron->getNextRunDate($now));
        $after = CarbonImmutable::instance($cron->getNextRunDate($now, 1));
        $minutes = (int) round($next->diffInSeconds($after, true) / 60);

        return match (true) {
            $minutes < 60 => sprintf('Cada %d min', $minutes),
            $minutes === 60 => 'Cada hora',
            $minutes < 1440 => sprintf('Cada %d h', intdiv($minutes, 60)),
            $minutes === 1440 => 'Cada día a las '.$next->format('H:i'),
            $minutes === 10080 => 'Cada semana',
            default => sprintf('Cada %d días', intdiv($minutes, 1440)),
        };
    }

    /**
     * @return list<array{command: string, lastRunAt: string|null, isOverdue: bool, frequency: string}>
     */
    private function scheduledJobs(CarbonImmutable $now): array
    {
        $jobs = [];

        foreach (self::SCHEDULE as $name => $expression) {
            $stored = Cache::get(self::LAST_RUN_PREFIX.$name);
            $lastRunAt = is_string($stored) ? CarbonImmutable::parse($stored) : null;

            $jobs[] = [
                'command' => $name,
                'lastRunAt' => $lastRunAt?->toIso8601String(),
                'isOverdue' => self::isOverdue($expression, $lastRunAt, $now),
                'frequency' => self::frequency($expression, $now),
            ];
        }

        return $jobs;
    }

    private function reverbIsUp(): bool
    {
        try {
            $host = (string) config('broadcasting.connections.reverb.options.host', '127.0.0.1');
            $port = (int) config('broadcasting.connections.reverb.options.port', 8080);
            $socket = @fsockopen($host, $port, $code, $message, 1.0);

            if ($socket === false) {
                return false;
            }

            fclose($socket);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $block
     * @return T|null
     */
    private function isolated(Closure $block): mixed
    {
        try {
            return $block();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
