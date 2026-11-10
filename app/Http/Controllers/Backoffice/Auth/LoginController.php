<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\Auth\LoginRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController extends Controller
{
    public const REMEMBERED_EMAIL_COOKIE = 'backoffice_remembered_email';

    private const REMEMBERED_EMAIL_MINUTES = 60 * 24 * 365;

    public function create(Request $request): Response
    {
        $rememberedEmail = $request->cookie(self::REMEMBERED_EMAIL_COOKIE);

        return Inertia::render('backoffice/auth/Login', [
            'status' => $request->session()->get('status'),
            'rememberedEmail' => is_string($rememberedEmail) && $rememberedEmail !== '' ? $rememberedEmail : null,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => CarbonImmutable::now()])->saveQuietly();
        }

        Cookie::queue($request->boolean('remember_email')
            ? Cookie::make(self::REMEMBERED_EMAIL_COOKIE, $request->email(), self::REMEMBERED_EMAIL_MINUTES)
            : Cookie::forget(self::REMEMBERED_EMAIL_COOKIE));

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
