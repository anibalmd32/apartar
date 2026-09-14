<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declara que una ruta sigue disponible con la organización **suspendida**
 * (`REQ-ORG-008` RB-3, `REQ-BOF-001` RB-9).
 *
 * No hace nada en tiempo de ejecución: es una **declaración**, y quien la lee
 * es `EnsureOrganizationIsActive`. El mecanismo es el mismo que el de las
 * superficies de tenancy (`T-007`) y por el mismo motivo: **falla cerrado**.
 * Una ruta nueva que no diga nada queda bloqueada en suspensión, en lugar de
 * quedar abierta porque nadie se acordó de listarla.
 *
 * Lo que sobrevive a la suspensión es corto y está en la spec: **leer la
 * cobranza y aprobar comprobantes**, más lo que hace falta para salir del
 * apuro —ver el aviso y pagar—. Cobrar lo que ya le deben no puede quedar
 * bloqueado por deberle una suscripción a la plataforma.
 */
final class AllowedDuringSuspension
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
