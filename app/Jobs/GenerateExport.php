<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Exports\CollectionsExport;
use App\Exports\CustomersExport;
use App\Exports\InventoryExport;
use App\Exports\PaymentsExport;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Notifications\ExportFinishedNotification;
use App\Queries\CollectionFilterInput;
use App\Queries\CustomerDirectoryFilterInput;
use App\Queries\InventoryFilters;
use App\Support\Settings;
use App\Support\TableState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(
        public readonly int $exportId,
        public readonly int $organizationId,
    ) {
        $this->onQueue('exports');
    }

    public function handle(TenantContext $context, LimitEnforcer $limits, UsageCalculator $usage, Settings $settings): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $context->runFor($organization, function () use ($organization, $limits, $usage, $settings): void {
            $export = $this->start();

            if (! $export instanceof ExportJob) {
                return;
            }

            $stored = null;

            try {
                $this->assertRoom($limits);

                $built = $this->build($export, $organization, $settings);
                $stored = sprintf('%d/%s.xlsx', $organization->getKey(), Str::uuid()->toString());

                if (! Excel::store($built, $stored, ExportJob::DISK, ExcelFormat::XLSX)) {
                    throw new RuntimeException('El disco de exportaciones no guardó el archivo.');
                }

                $export->forceFill([
                    'status' => ExportStatus::Completed,
                    'file_path' => $stored,
                    'file_size' => Storage::disk(ExportJob::DISK)->size($stored),
                    'row_count' => $built->rowCount,
                    'completed_at' => CarbonImmutable::now(),
                    'expires_at' => CarbonImmutable::now()->addDays(ExportJob::RETENTION_DAYS),
                ])->save();
            } catch (Throwable $exception) {
                if ($stored !== null) {
                    $this->discard($stored);
                }

                $export->forceFill([
                    'status' => ExportStatus::Failed,
                    'error_message' => mb_substr($this->reasonOf($exception), 0, 500),
                    'completed_at' => CarbonImmutable::now(),
                ])->save();

                if (! $exception instanceof PlanLimitReachedException && ! $exception instanceof CreationSuspendedException) {
                    report($exception);
                }
            }

            $this->measure($organization, $usage);
            $this->notify($export);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runFor($organization, function (): void {
            $export = ExportJob::query()->find($this->exportId);

            if (! $export instanceof ExportJob || ! $export->status->isInProgress()) {
                return;
            }

            $export->forceFill([
                'status' => ExportStatus::Failed,
                'error_message' => 'La exportación tardó demasiado y se interrumpió. Prueba con menos filas (más filtros) o vuelve a pedirla.',
                'completed_at' => CarbonImmutable::now(),
            ])->save();

            $this->notify($export);
        });
    }

    private function discard(string $path): void
    {
        try {
            Storage::disk(ExportJob::DISK)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function start(): ?ExportJob
    {
        return DB::transaction(function (): ?ExportJob {
            $export = ExportJob::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if (! $export instanceof ExportJob || $export->status !== ExportStatus::Queued) {
                return null;
            }

            $export->forceFill(['status' => ExportStatus::Processing, 'started_at' => CarbonImmutable::now()])->save();

            return $export;
        });
    }

    /**
     * @throws PlanLimitReachedException
     * @throws CreationSuspendedException
     */
    private function assertRoom(LimitEnforcer $limits): void
    {
        DB::transaction(fn () => $limits->assertCanCreate(UsageMetric::StorageMb));
    }

    private function build(ExportJob $export, Organization $organization, Settings $settings): CollectionsExport|CustomersExport|InventoryExport|PaymentsExport
    {
        $state = new TableState(page: 1, perPage: 1, sort: null, descending: false);
        $timezone = $organization->timezone;

        return match ($export->type) {
            ExportType::Collections => new CollectionsExport(
                CollectionFilterInput::resolve($export->filters, $state),
                $this->meta($state, $timezone) + ['Días de gracia' => (string) $settings->graceDays()],
                $timezone,
            ),
            ExportType::Customers => new CustomersExport(
                CustomerDirectoryFilterInput::resolve($export->filters, $state),
                $this->meta($state, $timezone),
            ),
            ExportType::Inventory => new InventoryExport(
                InventoryFilters::resolve($export->filters, $state),
                $this->meta($state, $timezone),
                $timezone,
            ),
            ExportType::Payments => new PaymentsExport($this->meta($state, $timezone), $timezone),
        };
    }

    /**
     * @param TableState<*> $state
     * @return array<string, string>
     */
    private function meta(TableState $state, string $timezone): array
    {
        $meta = ['Generado el' => CarbonImmutable::now($timezone)->format('d/m/Y H:i')];
        $applied = array_filter($state->filters(), fn (mixed $value): bool => $value !== null);
        $meta['Filtros'] = $applied === [] ? 'ninguno' : implode('; ', array_map(
            fn (string $key, mixed $value): string => $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value),
            array_keys($applied),
            $applied,
        ));

        if ($state->ignored() !== []) {
            $meta['Filtros ignorados'] = implode(', ', $state->ignored()).' (ya no existen o dejaron de valer)';
        }

        return $meta;
    }

    private function reasonOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof PlanLimitReachedException => 'Tu almacenamiento está lleno. Libera espacio (imágenes de productos que ya no uses; las exportaciones se borran solas a los siete días) o sube de plan, y vuelve a pedirla.',
            $exception instanceof CreationSuspendedException => $exception->getMessage(),
            default => 'No pudimos generar el archivo. Vuelve a pedirlo; si se repite, escríbenos.',
        };
    }

    private function measure(Organization $organization, UsageCalculator $usage): void
    {
        try {
            $usage->refresh($organization, UsageMetric::StorageMb);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function notify(ExportJob $export): void
    {
        $requester = $export->requester;

        if ($requester === null) {
            return;
        }

        try {
            $requester->notify(new ExportFinishedNotification($export->type, $export->status, route('exports.index')));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
