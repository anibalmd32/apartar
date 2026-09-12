<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Miembro del equipo de una organización.
 *
 * Primera tabla del tenant del proyecto: lleva `BelongsToOrganization`, así
 * que toda consulta filtra por la organización activa y toda creación la
 * rellena. La excepción es la **autenticación**, que ocurre antes de saber en
 * qué organización estamos y consulta a través de `TenantAwareUserProvider`.
 *
 * El modelo es anémico a propósito (`REQ-QAX-001` RB-3). La matriz de
 * capacidades del rol vive en `OrganizationRole` (`T-009`).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $password contraseña; nula mientras la invitación esté pendiente
 * @property string $role `owner` o `member`
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property bool $must_change_password
 * @property \Illuminate\Support\Carbon|null $invited_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasFactory, Notifiable;

    /**
     * `organization_id` no está aquí a propósito: lo rellena el trait y
     * ningún código de aplicación lo asigna (`REQ-ORG-002` RB-2).
     *
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
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'invited_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
