<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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

    public function role(): OrganizationRole
    {
        return OrganizationRole::from((string) $this->string('role'));
    }
}
