<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\CloseOrganizationAction;
use App\Domain\Tenancy\OrganizationHasActiveLayawaysException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\CloseOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class OrganizationClosureController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(CloseOrganizationRequest $request, CloseOrganizationAction $close): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        try {
            $close->handle($owner);
        } catch (OrganizationHasActiveLayawaysException $exception) {
            throw ValidationException::withMessages(['close' => $exception->getMessage()]);
        }

        return to_route('dashboard');
    }
}
