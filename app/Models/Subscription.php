<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property \Illuminate\Support\Carbon|null $grace_ends_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int|null $scheduled_plan_id
 * @property \Illuminate\Support\Carbon|null $scheduled_plan_at
 * @property \Illuminate\Support\Carbon|null $downgrade_applied_at
 * @property-read Plan $plan
 * @property-read Plan|null $scheduledPlan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'status',
        'started_at',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'cancelled_at',
        'scheduled_plan_id',
        'scheduled_plan_at',
        'downgrade_applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'scheduled_plan_at' => 'datetime',
            'downgrade_applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    public function hasScheduledChange(): bool
    {
        return $this->scheduled_plan_id !== null;
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isOpenEnded(): bool
    {
        return $this->current_period_end === null;
    }
}
