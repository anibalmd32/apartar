<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * El rol al que se cambia a alguien del equipo (`REQ-ORG-003` RB-3).
 *
 * Un solo campo, y aun así tiene `FormRequest` propio: la validación contra el
 * enum es lo que impide que llegue un `role=superadmin` y acabe escrito en la
 * columna. `D-4` deja la lista en dos y la deja cerrada, así que el sitio
 * donde eso se comprueba tiene que ser el enum y no una lista escrita a mano
 * en el controlador.
 *
 * El permiso no se comprueba aquí: lo hacen el middleware `owner` del grupo de
 * rutas y la policy dentro de la acción (`REQ-ORG-003` RB-4). Son dos capas y
 * ninguna sustituye a la otra —el grupo protege el camino, la policy el modelo
 * concreto sobre el que se opera—.
 */
final class ChangeTeamMemberRoleRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', new Enum(OrganizationRole::class)],
        ];
    }

    /**
     * Rol al que se cambia a la persona.
     */
    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->string('role'));
    }
}
