<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Domain\Tenancy\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property OrganizationRole $role
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property bool $must_change_password
 * @property \Illuminate\Support\Carbon|null $invited_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $invitation_token_hash
 * @property \Illuminate\Support\Carbon|null $invitation_expires_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'disabled_at',
        'must_change_password',
        'invited_at',
        'invitation_token_hash',
        'invitation_expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'invited_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function hasPendingInvitation(): bool
    {
        return $this->password === null;
    }
}
