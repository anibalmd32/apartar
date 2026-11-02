<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Actions\Organization\RegisterOrganizationAction;
use App\Domain\Tenancy\SlugTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\RegisterOrganizationRequest;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RegisterController extends Controller
{
    public function create(Request $request): Response
    {
        $plan = $this->chosenPlan($request->query('plan'));

        return Inertia::render('marketing/Register', [
            'plan' => $plan === null ? null : ['code' => $plan->code, 'name' => $plan->name],
        ]);
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

        if ($this->chosenPlan($request->input('plan')) !== null) {
            return redirect()->route('subscription.show');
        }

        return redirect()->intended(route('dashboard'));
    }

    private function chosenPlan(mixed $code): ?Plan
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return Plan::query()->public()->where('code', $code)->where('price_cents', '>', 0)->first();
    }

    private function startFreshSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
