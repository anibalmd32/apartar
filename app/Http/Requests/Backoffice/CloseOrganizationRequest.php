<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class CloseOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('close', app(TenantContext::class)->organization()) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['accepted'],
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.accepted' => 'Confirma que quieres cerrar el negocio.',
            'password.required' => 'Escribe tu contraseña para cerrar el negocio.',
            'password.current_password' => 'La contraseña no es correcta.',
        ];
    }
}
