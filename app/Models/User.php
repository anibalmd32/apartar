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
 * @property OrganizationRole $role rol dentro de su organización
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property bool $must_change_password
 * @property \Illuminate\Support\Carbon|null $invited_at
 * @property string|null $invitation_token_hash huella del enlace vivo; nula si no hay ninguno (`REQ-ORG-004` RB-4)
 * @property \Illuminate\Support\Carbon|null $invitation_expires_at
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
        'invitation_token_hash',
        'invitation_expires_at',
    ];

    /**
     * La huella del enlace de invitación se oculta junto a la contraseña: es
     * lo que abre la misma puerta (`REQ-ORG-004` RB-4), y serializar el modelo
     * a las page props del equipo no tiene por qué llevarla.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token_hash',
    ];

    /**
     * `role` se castea al enum y no se queda en `string` porque es el único
     * sitio donde vive el rol: leerlo como texto invitaría a comparar contra
     * literales repartidos por la aplicación, y la matriz de `REQ-ORG-003`
     * RB-1 dejaría de estar en un solo archivo.
     *
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
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Indica si la invitación sigue sin aceptarse (`REQ-ORG-004` RB-3).
     *
     * La marca es **no tener contraseña**, no tener `invited_at`: el segundo
     * se conserva después de aceptar —`TeamMember.invitedAt` lo muestra— y
     * dejar de contarlo como pendiente es exactamente lo que ocurre al fijar
     * la contraseña. Con la otra lectura, un miembro que entró hace un año
     * seguiría figurando como invitación pendiente para siempre.
     */
    public function hasPendingInvitation(): bool
    {
        return $this->password === null;
    }
}
