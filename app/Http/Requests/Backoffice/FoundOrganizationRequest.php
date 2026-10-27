<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Http\Requests\Marketing\RegisterOrganizationRequest;

final class FoundOrganizationRequest extends RegisterOrganizationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['organization_name', 'organization_slug']));
    }
}
