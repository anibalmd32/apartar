<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, LimitEnforcer $limits): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        // Una fila de `users` activa ocupa una unidad del plan
        // (`REQ-SUB-003` RB-2), y borrarla la libera en la misma transacción
        // (RB-3), con la fila releída bloqueada para no liberarla dos veces y
        // el contador cogido antes que ella, como en el resto del equipo.
        DB::transaction(function () use ($user, $limits): void {
            $limits->lockCounter(UsageMetric::Users);

            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return;
            }

            $locked->delete();

            if ($locked->disabled_at === null) {
                $limits->adjust(UsageMetric::Users, -1);
            }
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
