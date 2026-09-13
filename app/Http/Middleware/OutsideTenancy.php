<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declara que la ruta está fuera del modelo de tenancy: marketing, acceso y
 * endpoints públicos que no pertenecen a ninguna superficie de
 * `REQ-ORG-002` RB-4.
 *
 * No hace nada en tiempo de ejecución, y eso es deliberado: es una
 * **declaración**, no una guarda. La guarda ya existe y está más abajo —el
 * global scope de `BelongsToOrganization` lanza si alguien consulta una tabla
 * del tenant desde aquí—, así que duplicarla en el middleware solo añadiría
 * una segunda forma de fallar por lo mismo.
 *
 * Lo que sí hace es dejar rastro en el stack de la ruta, y ese rastro es lo
 * que lee la suite de `REQ-QAX-008`. La alternativa —no declarar nada— es
 * indistinguible de un olvido: una ruta de backoffice que alguien registró
 * fuera del grupo con `ResolveTenant` se vería exactamente igual.
 *
 * A diferencia de `OperatesWithoutTenant`, que gobierna `/plataforma/*` y
 * exige que no haya organización fijada, esta no comprueba el contexto: en la
 * suite el tenant lo puede haber dejado puesto una petición anterior del mismo
 * proceso, y hacer fallar la petición por eso sería un falso positivo con
 * forma de error de aislamiento.
 */
final class OutsideTenancy implements DeclaresNoTenantSurface
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
