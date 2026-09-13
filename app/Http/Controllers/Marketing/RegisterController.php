<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Actions\Organization\RegisterOrganizationAction;
use App\Domain\Tenancy\SlugTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\RegisterOrganizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La puerta de entrada al producto (`REQ-ORG-001`).
 *
 * El controlador es fino a propósito: valida, delega en la acción y decide qué
 * ve la persona. Las cuatro creaciones, la desambiguación del slug y el inicio
 * de sesión viven en `RegisterOrganizationAction`, que es quien puede
 * garantizar que ocurren en una sola transacción.
 */
final class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('marketing/Register');
    }

    /**
     * @throws ValidationException si el enlace pedido ya está tomado
     */
    public function store(RegisterOrganizationRequest $request, RegisterOrganizationAction $register): RedirectResponse
    {
        try {
            $register->handle($request->toData());
        } catch (SlugTakenException $exception) {
            // SP-1 · se devuelve **con la variante libre dentro del mensaje**,
            // que es lo único accionable que se le puede decir a quien choca
            // con el nombre de un negocio que no conoce.
            throw ValidationException::withMessages([
                'organization_slug' => $exception->getMessage(),
            ]);
        }

        $this->startFreshSession($request);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Rota el identificador de sesión después de autenticar.
     *
     * La acción deja la sesión iniciada; esto cierra la ventana de fijación de
     * sesión, que es la misma precaución que toma el login heredado.
     */
    private function startFreshSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
