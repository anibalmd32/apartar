<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property int|null $installment_id
 * @property int|null $layaway_id
 * @property string $type
 * @property string $channel
 * @property string $dedupe_key
 * @property \Illuminate\Support\Carbon $sent_at
 */
class NotificationDispatch extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
