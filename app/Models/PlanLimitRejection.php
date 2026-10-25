<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property UsageMetric $metric
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class PlanLimitRejection extends Model
{
    use BelongsToOrganization;

    /**
     * @var list<string>
     */
    protected $fillable = ['metric', 'occurred_at'];

    public const THROTTLE_MINUTES = 60;

    public static function record(UsageMetric $metric): void
    {
        $recent = self::query()
            ->where('metric', $metric)
            ->where('occurred_at', '>=', now()->subMinutes(self::THROTTLE_MINUTES))
            ->exists();

        if ($recent) {
            return;
        }

        self::query()->create(['metric' => $metric, 'occurred_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'occurred_at' => 'datetime',
        ];
    }
}
