<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Auth\TeamMemberships;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class ForcedPasswordChangeController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('backoffice/auth/ChangePassword');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => (string) $request->string('password'),
            'must_change_password' => false,
        ])->save();

        app(TeamMemberships::class)->syncPassword($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Contraseña actualizada.');
    }
}
