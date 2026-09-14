<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Convierte en `409` la petición de Inertia cuya sesión caducó
 * (`REQ-BOF-001` SP-6).
 *
 * El síntoma sin esto es de los que nadie sabe describir: el navegador tiene
 * una página cargada, manda un XHR, el servidor responde `419` con el HTML del
 * acceso dentro, e Inertia —que espera una respuesta suya— no sabe qué hacer
 * con eso. La pantalla se queda igual y parece que el botón no funciona.
 *
 * `Inertia::location()` responde `409` con la cabecera `X-Inertia-Location`, y
 * el cliente **recarga entero** hasta el acceso. Es lo único honesto cuando la
 * sesión ya no existe: lo que hay en pantalla pertenece a una sesión que
 * terminó.
 *
 * Vive en una clase y no en un *closure* dentro de `bootstrap/app.php` para
 * poder llevar esta explicación al lado del código, y porque un archivo sin
 * `namespace` no puede importar `Throwable` sin que PHP avise en cada
 * petición.
 */
final class ExpiredInertiaSession
{
    /**
     * Código con el que Laravel responde a una sesión o un token caducados.
     */
    private const EXPIRED = 419;

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if ($response->getStatusCode() !== self::EXPIRED || ! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        return Inertia::location(route('login'));
    }
}
