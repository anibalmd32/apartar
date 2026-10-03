<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StartScopedSessionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La entrada al portal del cliente (`REQ-IDN-008`, `REQ-IDN-004`).
 *
 * `/mi-cuenta/acceso` ofrecerá tres vías (`REQ-ACC-005`): Google (H6), el
 * enlace por correo (`T-050`) y **documento + código**, que es la que hay hoy
 * y la que no deja a nadie fuera. Ninguna de estas rutas tiene tenant: el
 * negocio sale del código, dentro de la acción.
 */
final class AccessController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return Inertia::render('portal/Access', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Documento + código → sesión acotada, y al destino que se pidió (SP-1).
     */
    public function storeCode(StartScopedSessionRequest $request, StartScopedSessionAction $start): RedirectResponse
    {
        $request->authenticate($start);

        // Sesión nueva: el identificador de antes del acceso no sirve para
        // secuestrar la de después.
        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    /**
     * Cerrar sesión (`REQ-IDN-004` RB-5): se invalida la sesión entera —la
     * acotada y, cuando exista, la de cuenta— y se rota el token CSRF.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.access');
    }
}
