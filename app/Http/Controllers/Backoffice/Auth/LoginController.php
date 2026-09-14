<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La puerta del backoffice (`REQ-BOF-001`).
 *
 * **No hay selector de organización** (RB-2). `users.email` es único en toda
 * la plataforma —por eso figura en la lista blanca de `GlobalUniqueExceptions`
 * desde `T-005`— y con eso basta: el correo identifica a la persona, la
 * persona trae su `organization_id`, y `ResolveTenant` fija el tenant en la
 * petición siguiente sin que nadie elija nada.
 *
 * La sesión se regenera al entrar y se invalida al salir (RB-7): lo primero
 * cierra la ventana de fijación de sesión, lo segundo evita que el botón
 * «atrás» de un ordenador compartido devuelva a alguien dentro.
 */
final class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('backoffice/auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

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
