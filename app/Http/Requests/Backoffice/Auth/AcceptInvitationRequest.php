<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * La contraseña con la que alguien acepta su invitación
 * (`REQ-ORG-004` RB-5).
 *
 * **La misma política que el resto de las puertas del producto**
 * (`REQ-BOF-001` RB-6): doce caracteres, mayúsculas y minúsculas, números y
 * comprobada contra filtraciones conocidas. Aflojarla aquí sería aflojarla
 * para todo el equipo menos para el dueño, que es justo al revés de lo que
 * conviene: el `owner` sabe lo que se juega, el empleado que entra el primer
 * día no.
 *
 * No se pide la contraseña actual porque no hay ninguna: el invitado nace sin
 * contraseña utilizable (RB-3). Es el mismo caso que el cambio obligatorio.
 */
final class AcceptInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->uncompromised()],
        ];
    }
}
