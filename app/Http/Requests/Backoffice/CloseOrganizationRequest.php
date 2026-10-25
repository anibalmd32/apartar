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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmed.accepted' => 'Confirma que quieres cerrar el negocio.',
        ];
    }
}
