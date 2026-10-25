<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\UsageCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property UsageMetric $metric
 * @property int $value
 * @property \Illuminate\Support\Carbon $computed_at
 */
class UsageCounter extends Model
{
    /** @use HasFactory<UsageCounterFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'metric',
        'value',
        'computed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'value' => 'integer',
            'computed_at' => 'datetime',
        ];
    }
}
