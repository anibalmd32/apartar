<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $platform_user_id
 * @property int|null $target_organization_id
 * @property string $action
 * @property string $description
 * @property array<string, mixed> $properties
 * @property \Illuminate\Support\Carbon $created_at
 */
class PlatformActivity extends Model
{
    protected $table = 'platform_activity_log';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['properties' => 'array'];
    }
}
