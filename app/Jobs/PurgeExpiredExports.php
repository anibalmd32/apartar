<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Export\ExportStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Queries\Platform\ExpiredExports;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeExpiredExports implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('exports');
    }

    public function handle(ExpiredExports $expired, TenantContext $context, UsageCalculator $usage): int
    {
        $now = CarbonImmutable::now();
        $purged = 0;

        foreach ($expired->organizationIds($now) as $organizationId) {
            $organization = Organization::query()->withTrashed()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            try {
                $count = $context->runFor($organization, fn (): int => $this->purge($now));
                $purged += $count;

                if ($count > 0) {
                    $usage->refresh($organization, UsageMetric::StorageMb);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Log::info('exports.purged', ['purged' => $purged]);

        return $purged;
    }

    private function purge(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            $exports = ExportJob::query()
                ->where('status', ExportStatus::Completed)
                ->where('expires_at', '<=', $now)
                ->lockForUpdate()
                ->get();

            $paths = [];

            foreach ($exports as $export) {
                if ($export->file_path !== null) {
                    $paths[] = $export->file_path;
                }

                $export->forceFill(['status' => ExportStatus::Expired])->save();
            }

            DB::afterCommit(fn () => Storage::disk(ExportJob::DISK)->delete($paths));

            return $exports->count();
        });
    }
}
