<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $catalog_id
 * @property string $event_name
 * @property string $visitor_id
 * @property int|null $account_id
 * @property int|null $customer_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $payload
 * @property string $session_id
 * @property string $ip_hash
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class TelemetryEvent extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
