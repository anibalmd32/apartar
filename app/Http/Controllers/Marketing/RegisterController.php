<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Actions\Organization\RegisterOrganizationAction;
use App\Domain\Tenancy\SlugTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\RegisterOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('marketing/Register');
    }

    /**
     * @throws ValidationException
     */
    public function slug(Request $request, RegisterOrganizationAction $register): JsonResponse
    {
        return response()->json($register->availability(mb_substr((string) $request->query('slug', ''), 0, 100)));
    }

    public function store(RegisterOrganizationRequest $request, RegisterOrganizationAction $register): RedirectResponse
    {
        try {
            $register->handle($request->toData());
        } catch (SlugTakenException $exception) {
            throw ValidationException::withMessages([
                'organization_slug' => $exception->getMessage(),
            ]);
        }

        $this->startFreshSession($request);

        return redirect()->intended(route('dashboard'));
    }

    private function startFreshSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
