<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * @property int $id
 * @property int $organization_id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $event
 * @property string|null $causer_type
 * @property int|null $causer_id
 */
class AuditEntry extends Activity
{
    use BelongsToOrganization;

    public const LOG_NAME = 'audit';

    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeAudited(Builder $query): Builder
    {
        return $query->where('log_name', self::LOG_NAME);
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeOfEvent(Builder $query, AuditEvent $event): Builder
    {
        return $query->where('event', $event->value);
    }
}
