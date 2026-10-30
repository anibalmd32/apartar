<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Export\ExportStatus;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Models\Catalog;
use App\Models\ExportJob;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class UsageAggregates
{
    private const BYTES_PER_MB = 1024 * 1024;

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    public function measure(UsageMetric $metric, array $organizationIds, ?CarbonImmutable $now = null, bool $locking = false): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();

        $measured = match ($metric) {
            UsageMetric::Users => $this->countPer(
                User::query()->withoutTenantScope()->whereNull('disabled_at'),
                $organizationIds,
                $locking,
            ),

            UsageMetric::Catalogs => $this->countPer(
                Catalog::query()->withoutTenantScope()
                    ->whereNotNull('published_at')
                    ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now)),
                $organizationIds,
                $locking,
            ),

            UsageMetric::Products => $this->countPer(
                Product::query()->withoutTenantScope()->whereIn('status', $this->productStatusesThatCount()),
                $organizationIds,
                $locking,
            ),

            UsageMetric::ActiveLayaways => $this->countPer(
                Layaway::query()->withoutTenantScope()->whereIn('status', $this->layawayStatusesThatCount()),
                $organizationIds,
                $locking,
            ),

            UsageMetric::StorageMb => $this->megabytesPer($organizationIds, $locking),

            UsageMetric::PaymentAccounts => $this->countPer(
                PaymentAccount::query()->withoutTenantScope()->where('is_active', true),
                $organizationIds,
                $locking,
            ),
        };

        return array_replace(array_fill_keys($organizationIds, 0), $measured);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function countPer(Builder $query, array $organizationIds, bool $locking): array
    {
        $table = $query->getModel()->getTable();

        return $this->totals($query
            ->whereIn("{$table}.organization_id", $organizationIds)
            ->groupBy("{$table}.organization_id")
            ->selectRaw("{$table}.organization_id AS organization, COUNT(*) AS total"), $locking);
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function megabytesPer(array $organizationIds, bool $locking): array
    {
        $megabytes = [];

        foreach ($this->bytesPer($organizationIds, $locking) as $organization => $bytes) {
            $megabytes[$organization] = self::megabytes($bytes);
        }

        return $megabytes;
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    public function bytesPer(array $organizationIds, bool $locking = false): array
    {
        $receipts = $this->totals(Receipt::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->groupBy('organization_id')
            ->selectRaw('organization_id AS organization, SUM(file_size) AS total'), $locking);

        $attachments = $this->totals(Payment::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->whereNotNull('file_size')
            ->groupBy('organization_id')
            ->selectRaw('organization_id AS organization, SUM(file_size) AS total'), false);

        $media = $this->totals(Product::query()->withoutTenantScope()->withTrashed()
            ->join('media', function ($join): void {
                $join->on('media.model_id', '=', 'products.id')
                    ->where('media.model_type', '=', (new Product)->getMorphClass());
            })
            ->whereIn('products.organization_id', $organizationIds)
            ->groupBy('products.organization_id')
            ->selectRaw('products.organization_id AS organization, SUM(media.size) AS total'), $locking);

        $exports = $this->totals(ExportJob::query()->withoutTenantScope()
            ->where('status', ExportStatus::Completed)
            ->whereIn('organization_id', $organizationIds)
            ->groupBy('organization_id')
            ->selectRaw('organization_id AS organization, SUM(file_size) AS total'), $locking);

        $bytes = [];

        foreach ($organizationIds as $organization) {
            $bytes[$organization] = (int) ($receipts[$organization] ?? 0) + (int) ($attachments[$organization] ?? 0) + (int) ($media[$organization] ?? 0) + (int) ($exports[$organization] ?? 0);
        }

        return $bytes;
    }

    public static function megabytes(int $bytes): int
    {
        return (int) ceil($bytes / self::BYTES_PER_MB);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<int, int>
     */
    private function totals(Builder $query, bool $locking): array
    {
        $totals = [];

        if ($locking) {
            $query->sharedLock();
        }

        foreach ($query->toBase()->get() as $row) {
            $totals[(int) $row->organization] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function productStatusesThatCount(): array
    {
        return array_values(array_map(
            fn (ProductStatus $status): string => $status->value,
            array_filter(ProductStatus::cases(), fn (ProductStatus $status): bool => $status->countsTowardPlanLimit()),
        ));
    }

    /**
     * @return list<string>
     */
    private function layawayStatusesThatCount(): array
    {
        return array_values(array_map(
            fn (LayawayStatus $status): string => $status->value,
            array_filter(LayawayStatus::cases(), fn (LayawayStatus $status): bool => $status->countsTowardPlanLimit()),
        ));
    }
}
