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

/**
 * Purga las exportaciones a los siete días (`REQ-BOF-043` RB-7, SP-4,
 * `T-070`): borra el archivo, deja la fila como `expired` —su descarga
 * responde `410` con la explicación— y **libera la cuota**: `storage_mb` se
 * vuelve a medir después del `COMMIT` de cada organización.
 *
 * Diaria e idempotente: lo ya purgado no vuelve a entrar. Una organización que
 * falla no tumba la pasada. Un archivo que ya no estaba en el disco no es un
 * error: la fila caduca igual.
 */
class PurgeExpiredExports implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('exports');
    }

    /**
     * @return int cuántas purgó
     */
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

            // Los archivos se borran **después** del `COMMIT`: si la transacción
            // se deshiciera, una fila `completed` sin archivo sería un `500` al
            // descargar; un archivo que sobreviviera sería espacio en disco, no
            // un error que vea nadie.
            DB::afterCommit(fn () => Storage::disk(ExportJob::DISK)->delete($paths));

            return $exports->count();
        });
    }
}
