<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $platform_user_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon $granted_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by
 */
class SupportAccessGrant extends Model
{
    use BelongsToOrganization;

    public function isActive(?CarbonImmutable $now = null): bool
    {
        return $this->revoked_at === null && $this->expires_at->greaterThan($now ?? CarbonImmutable::now());
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
