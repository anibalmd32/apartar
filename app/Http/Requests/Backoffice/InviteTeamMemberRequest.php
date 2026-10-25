<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class InviteTeamMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->where('organization_id', app(TenantContext::class)->organizationId())],
            'role' => ['required', new Enum(OrganizationRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese correo ya es del equipo de tu negocio.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->string('email'))),
        ]);
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->string('role'));
    }
}
