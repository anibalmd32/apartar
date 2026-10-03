<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\ConsumeLoginTokenAction;
use App\Actions\Customer\IssueLoginLinkAction;
use App\Domain\Customer\UnusableLoginLinkException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RequestLoginLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * El enlace de acceso por correo (`REQ-IDN-003`): pedirlo, confirmarlo y
 * canjearlo.
 *
 * Ninguna de estas rutas tiene tenant: el de la solicitud lo descubre la
 * acción por documento y correo, y el del canje lo dice el prefijo del token.
 */
final class LoginLinkController extends Controller
{
    /**
     * Pedir el enlace. La respuesta es la misma coincidan o no los datos
     * (RB-2, SP-3), y no dice si se envió algo.
     */
    public function store(RequestLoginLinkRequest $request, IssueLoginLinkAction $issue): RedirectResponse
    {
        $request->issue($issue);

        return redirect()->route('portal.access')->with('status', RequestLoginLinkRequest::SENT_MESSAGE);
    }

    /**
     * RB-9 · el `GET` solo enseña la confirmación: el cliente de correo que
     * precarga enlaces no gasta el token (SP-7). Ni siquiera se mira si sirve,
     * para que la precarga no deje huella en ninguna parte.
     */
    public function show(string $token): Response
    {
        return Inertia::render('portal/LoginLink', ['token' => $token]);
    }

    /**
     * El canje, por `POST`. Un enlace que no sirve —caducado, usado o
     * inexistente— da **la misma** pantalla con un `404` (SP-1, SP-2).
     */
    public function consume(Request $request, string $token, ConsumeLoginTokenAction $consume): RedirectResponse|HttpResponse
    {
        try {
            $consume->handle($token);
        } catch (UnusableLoginLinkException $unusable) {
            Log::warning('portal.login_link.unusable', ['ip' => $request->ip(), 'reason' => $unusable->reason]);

            return Inertia::render('portal/LoginLinkUnusable', ['message' => $unusable->getMessage()])
                ->toResponse($request)
                ->setStatusCode(HttpResponse::HTTP_NOT_FOUND);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }
}
