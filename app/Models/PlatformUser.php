<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property bool $must_change_password
 */
class PlatformUser extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\PlatformUserFactory> */
    use HasFactory;

    protected $hidden = ['password'];

    protected $rememberTokenName = '';

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * @return HasMany<SupportAccessGrant, $this>
     */
    public function supportGrants(): HasMany
    {
        return $this->hasMany(SupportAccessGrant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'disabled_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }
}
