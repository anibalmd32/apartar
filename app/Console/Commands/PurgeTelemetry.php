<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telemetry\TelemetryRetention;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PurgeTelemetry extends Command
{
    public const MAX_ROWS = 100_000;

    public const BATCH = 5_000;

    public const LAST_RUN_KEY = 'telemetry:purge:last';

    protected $signature = 'telemetry:purge {--max= : Tope de filas de esta pasada}';

    protected $description = 'Purga la telemetría vencida, preservando antes sus agregados mensuales';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $budget = is_numeric($this->option('max')) ? max(0, (int) $this->option('max')) : self::MAX_ROWS;
        $retention = $this->retentionByOrganization();
        $purged = 0;

        foreach ($this->organizationsWithTelemetry() as $organizationId) {
            $days = $organizationId === null
                ? TelemetryRetention::platformDefaultDays()
                : ($retention[$organizationId] ?? TelemetryRetention::WITHOUT_PLAN_DAYS);
            $cutoff = TelemetryRetention::cutoff($days, $now);

            while ($budget > 0) {
                $done = $this->purgeBatch($organizationId, $cutoff, min(self::BATCH, $budget), $now);

                if ($done === 0) {
                    break;
                }

                $purged += $done;
                $budget -= $done;
            }
        }

        Cache::forever(self::LAST_RUN_KEY, ['purged' => $purged, 'at' => $now->toIso8601String(), 'exhausted' => $budget === 0]);

        $this->info(sprintf('%d eventos purgados.%s', $purged, $budget === 0 ? ' Quedó tope: sigue en la próxima pasada.' : ''));

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function retentionByOrganization(): array
    {
        $days = [];

        foreach (Subscription::query()->withoutTenantScope()->with('plan')->get() as $subscription) {
            $days[(int) $subscription->organization_id] = TelemetryRetention::daysFor($subscription->plan);
        }

        return $days;
    }

    /**
     * @return list<int|null>
     */
    private function organizationsWithTelemetry(): array
    {
        return DB::table('telemetry_events')->distinct()->orderBy('organization_id')->pluck('organization_id')
            ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    private function purgeBatch(?int $organizationId, CarbonImmutable $cutoff, int $size, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($organizationId, $cutoff, $size, $now): int {
            $ids = $this->expired($organizationId, $cutoff)->orderBy('id')->limit($size)->lockForUpdate()->pluck('id')->all();

            if ($ids === []) {
                return 0;
            }

            $groups = DB::table('telemetry_events')
                ->whereIn('id', $ids)
                ->selectRaw("event_name, DATE_FORMAT(occurred_at, '%Y-%m') AS ym, COUNT(*) AS total")
                ->groupBy('event_name', 'ym')
                ->get();

            foreach ($groups as $group) {
                $this->accumulate($organizationId, (string) $group->event_name, (string) $group->ym, (int) $group->total, $now);
            }

            return DB::table('telemetry_events')->whereIn('id', $ids)->delete();
        });
    }

    private function expired(?int $organizationId, CarbonImmutable $cutoff): Builder
    {
        return DB::table('telemetry_events')
            ->when($organizationId === null, fn (Builder $query) => $query->whereNull('organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->where('occurred_at', '<', $cutoff);
    }

    private function accumulate(?int $organizationId, string $event, string $yearMonth, int $count, CarbonImmutable $now): void
    {
        $existing = DB::table('telemetry_monthly_aggregates')
            ->when($organizationId === null, fn (Builder $query) => $query->whereNull('organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->where('event_name', $event)
            ->where('year_month', $yearMonth)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            DB::table('telemetry_monthly_aggregates')->where('id', $existing->id)->update(['count' => DB::raw('`count` + '.$count), 'updated_at' => $now]);

            return;
        }

        DB::table('telemetry_monthly_aggregates')->insert([
            'organization_id' => $organizationId,
            'event_name' => $event,
            'year_month' => $yearMonth,
            'count' => $count,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
