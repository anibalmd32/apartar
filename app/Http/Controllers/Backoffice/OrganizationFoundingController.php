<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\FoundOrganizationAction;
use App\Actions\Organization\RegisterOrganizationAction;
use App\Auth\TeamMemberships;
use App\Domain\Tenancy\OwnedOrganizationLimitException;
use App\Domain\Tenancy\SlugTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\FoundOrganizationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationFoundingController extends Controller
{
    public function create(Request $request, TeamMemberships $memberships): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $memberships->mayFound($user->email)) {
            return to_route('dashboard')->with('status', OwnedOrganizationLimitException::reached((int) config('platform.max_owned_organizations'))->getMessage());
        }

        return Inertia::render('backoffice/organizations/Create');
    }

    public function slug(Request $request, RegisterOrganizationAction $register): JsonResponse
    {
        return response()->json($register->availability(mb_substr((string) $request->query('slug', ''), 0, 100)));
    }

    /**
     * @throws ValidationException
     */
    public function store(FoundOrganizationRequest $request, FoundOrganizationAction $found): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->toData();

        try {
            $organization = $found->handle($user, $data->organizationName, $data->organizationSlug);
        } catch (OwnedOrganizationLimitException $exception) {
            throw ValidationException::withMessages(['organization_name' => $exception->getMessage()]);
        } catch (SlugTakenException $exception) {
            throw ValidationException::withMessages(['organization_slug' => $exception->getMessage()]);
        }

        $request->session()->regenerate();
        $owner = $request->user();

        if ($owner instanceof User) {
            $owner->forceFill(['last_login_at' => CarbonImmutable::now()])->saveQuietly();
        }

        return to_route('dashboard')->with('status', sprintf('Creaste %s. Estás en tu negocio nuevo.', $organization->name));
    }
}
