<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Los datos de una invitación al equipo (`REQ-ORG-004`).
 *
 * **La unicidad del correo se valida aquí a pesar de tener el índice detrás**,
 * y por el mismo motivo que `T-010` la validó en la acción y no en el
 * `FormRequest`: hacen falta las dos. La regla `unique` da el mensaje junto al
 * campo, que es lo único que le sirve a quien está escribiendo; el índice
 * cierra la ventana entre la consulta y el `INSERT`, que es lo único que sirve
 * cuando llegan dos a la vez.
 *
 * Y la consulta de la regla `unique` es **global a propósito**: va por el
 * verificador de presencia del framework, que consulta la tabla sin pasar por
 * Eloquent y por tanto sin el global scope del tenant. Es lo que hace falta:
 * `users.email` es único en toda la plataforma (`REQ-ORG-001` RB-3), así que
 * un correo tomado en otro negocio tiene que rebotar aquí y no en el índice.
 *
 * El permiso no se comprueba en este archivo: lo hacen el middleware `owner`
 * del grupo de rutas y la policy dentro de la acción (`REQ-ORG-003` RB-4).
 */
final class InviteTeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', new Enum(OrganizationRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese correo ya tiene una cuenta de equipo en Apartar. Usa otra dirección.',
        ];
    }

    /**
     * El correo se normaliza antes de validar.
     *
     * En minúsculas porque el índice único es sensible a cómo se escriba lo
     * que no debería importar: `Ana@X.com` y `ana@x.com` son la misma persona
     * y el índice las dejaría convivir, cada una con su cuenta y su sesión.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->string('email'))),
        ]);
    }

    /**
     * Rol con el que entra la persona invitada.
     */
    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->string('role'));
    }
}
