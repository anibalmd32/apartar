<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\PlanAllowance;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $price_cents
 * @property string $interval
 * @property bool $is_public
 * @property int $position
 * @property int|null $max_users
 * @property int|null $max_catalogs
 * @property int|null $max_products
 * @property int|null $max_products_per_catalog
 * @property int|null $max_active_layaways
 * @property int|null $max_storage_mb
 * @property int|null $max_payment_accounts
 * @property array<string, mixed>|null $features
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'interval',
        'is_public',
        'position',
        'max_users',
        'max_catalogs',
        'max_products',
        'max_products_per_catalog',
        'max_active_layaways',
        'max_storage_mb',
        'max_payment_accounts',
        'features',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_public' => 'boolean',
            'position' => 'integer',
            'max_users' => 'integer',
            'max_catalogs' => 'integer',
            'max_products' => 'integer',
            'max_products_per_catalog' => 'integer',
            'max_active_layaways' => 'integer',
            'max_storage_mb' => 'integer',
            'max_payment_accounts' => 'integer',
            'features' => 'array',
        ];
    }

    public function limitFor(UsageMetric $metric): ?int
    {
        $limit = $this->getAttribute($metric->planColumn());

        return $limit === null ? null : (int) $limit;
    }

    public function maxProductsPerCatalog(): ?int
    {
        return $this->max_products_per_catalog;
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        return (bool) ($this->features[$feature->value] ?? false);
    }

    public function allowance(PlanAllowance $allowance): ?int
    {
        $value = $this->features[$allowance->value] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function showsPlatformBadge(): bool
    {
        return ! $this->hasFeature(PlanFeature::Branding);
    }

    /**
     * @return list<PlanFeature>
     */
    public function enabledFeatures(): array
    {
        return array_values(array_filter(
            PlanFeature::cases(),
            fn (PlanFeature $feature): bool => $this->hasFeature($feature),
        ));
    }

    /**
     * @throws LogicException
     */
    public function periodEndFrom(CarbonInterface $start, ?int $anchorDay = null): CarbonImmutable
    {
        return match ($this->interval) {
            'monthly' => $anchorDay === null
                ? $start->toImmutable()->addMonthNoOverflow()
                : $start->toImmutable()->addMonthNoOverflow()->day(1)->day(min($anchorDay, $start->toImmutable()->addMonthNoOverflow()->daysInMonth)),
            default => throw new LogicException(sprintf('El plan %s tiene un intervalo «%s» que nadie sabe medir.', $this->code, $this->interval)),
        };
    }

    public function ranksAbove(self $other): bool
    {
        return $this->position > $other->position;
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->orderBy('position');
    }
}
