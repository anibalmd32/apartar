<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\LoginRequest;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('platform/auth/Login', ['status' => $request->session()->get('status')]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        $request->session()->regenerate();
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entró en la plataforma.', $user, properties: ['ip' => $request->ip()]);

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }

    public function editPassword(): Response
    {
        return Inertia::render('platform/auth/ChangePassword');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = Auth::guard('platform')->user();
        abort_if($user === null, 401);

        $user->forceFill(['password' => (string) $request->string('password'), 'must_change_password' => false])->save();
        $request->session()->regenerate();

        return redirect()->route('platform.dashboard')->with('status', 'Contraseña actualizada.');
    }
}
