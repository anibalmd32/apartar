<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra un grupo de rutas al `owner` (`REQ-ORG-003` RB-4 y SP-1).
 *
 * Es la primera de las dos protecciones que RB-4 exige, y la que no se puede
 * saltar escribiendo la URL a mano: ocultar la sección en el menú es cortesía,
 * esto es el control de acceso. La segunda es la policy de cada operación, que
 * sigue haciendo falta —un grupo de rutas protege el camino, no el modelo
 * concreto sobre el que se opera—.
 *
 * **El rol se lee de la base de datos en cada petición** (RB-6). No hay nada
 * que hacer para conseguirlo salvo no estropearlo: el guard de sesión trae al
 * usuario de `users` en cada petición, así que basta con mirar su columna y no
 * guardar el rol en la sesión. Es justo lo contrario de lo que parece
 * eficiente, y es lo que hace que degradar a alguien surta efecto en su
 * siguiente clic en lugar de la próxima vez que inicie sesión.
 *
 * Responde `403` y no `404`: aquí no se oculta la existencia de nada, porque
 * el recurso es de la organización de quien pregunta (`REQ-ORG-002` RB-6 habla
 * de recursos **ajenos**, que son otra cosa).
 */
final class EnsureIsOwner
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== OrganizationRole::Owner) {
            abort(Response::HTTP_FORBIDDEN, 'Esta sección es del dueño de la organización.');
        }

        return $next($request);
    }
}
