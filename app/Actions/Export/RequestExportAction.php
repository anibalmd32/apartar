<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\GenerateExport;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Models\Layaway;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RequestExportAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws PlanFeatureUnavailableException
     * @throws AuthorizationException
     */
    public function handle(ExportRequestData $data, User $actor): ExportJob
    {
        $this->limits->assertHasFeature(PlanFeature::ExcelExport);
        $this->authorize($data->type, $actor);

        return DB::transaction(function () use ($data, $actor): ExportJob {
            $this->failTheStale($data->type);

            $running = ExportJob::query()
                ->where('type', $data->type)
                ->whereIn('status', [ExportStatus::Queued, ExportStatus::Processing])
                ->lockForUpdate()
                ->first();

            if ($running instanceof ExportJob) {
                return $running;
            }

            $export = new ExportJob;
            $export->forceFill([
                'organization_id' => $this->context->organization()->getKey(),
                'requested_by' => $actor->getKey(),
                'type' => $data->type,
                'status' => ExportStatus::Queued,
                'filters' => $data->filters,
            ])->save();

            GenerateExport::dispatch((int) $export->getKey(), (int) $export->organization_id)->afterCommit();

            return $export;
        }, attempts: 3);
    }

    private function failTheStale(ExportType $type): void
    {
        ExportJob::query()
            ->where('type', $type)
            ->whereIn('status', [ExportStatus::Queued, ExportStatus::Processing])
            ->where('created_at', '<', CarbonImmutable::now()->subMinutes(ExportJob::STALE_MINUTES))
            ->lockForUpdate()
            ->get()
            ->each(fn (ExportJob $stale) => $stale->forceFill([
                'status' => ExportStatus::Failed,
                'error_message' => 'Esta exportación se interrumpió. Pide otra.',
                'completed_at' => CarbonImmutable::now(),
            ])->save());
    }

    public function allows(ExportType $type, User $actor): bool
    {
        $gate = Gate::forUser($actor);

        return match ($type) {
            ExportType::Collections => $gate->allows('viewAny', Layaway::class),
            ExportType::Customers => $gate->allows('viewAny', Customer::class),
            ExportType::Inventory => $gate->allows('manageProducts', $this->context->organization()),
        };
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(ExportType $type, User $actor): void
    {
        if (! $this->allows($type, $actor)) {
            throw new AuthorizationException(sprintf('No puedes exportar %s.', mb_strtolower($type->label())));
        }
    }
}
