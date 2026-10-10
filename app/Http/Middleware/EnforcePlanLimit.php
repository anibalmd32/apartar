<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra una ruta a los planes que no incluyen su función
 * (`REQ-SUB-004` RB-3, RB-7, SP-3, SP-7, `T-067`).
 *
 * Se declara en la ruta con la clave de la función:
 * `->middleware('plan-feature:excel_export')`. Sin ella en el plan, la
 * petición no llega al controlador y responde **`403` con el motivo y el plan
 * que la incluye**, nunca `404`: ocultar que existe impediría entender que
 * basta con subir de plan (SP-3). Es la comprobación de servidor que manda
 * aunque la pantalla deshabilite el botón (SP-7).
 *
 * **No decide nada por su cuenta** (RB-1): pregunta a
 * `LimitEnforcer::assertHasFeature()`, que es quien lanza
 * `PlanFeatureUnavailableException`, y la respuesta la da su renderizado en
 * `bootstrap/app.php`.
 *
 * **Solo funciones, no cupos.** Un cupo se comprueba dentro de la transacción
 * de la Action, con el contador bloqueado (RB-2): una comprobación aquí sería
 * una segunda lectura sin bloqueo, que dos peticiones simultáneas pasarían a
 * la vez y que la Action tendría que repetir igualmente.
 *
 * Las cuatro funciones de `REQ-SUB-004` RB-3 que se comprueban así
 * —exportar, Web Push, avisar a suscriptores y la analítica completa— no
 * tienen ruta todavía; cada tarea que las construye (`T-070`, `T-083`,
 * `T-078`, `T-085`) declara este middleware en la suya.
 */
final class EnforcePlanLimit
{
    public function __construct(private readonly LimitEnforcer $limits) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws InvalidArgumentException si la ruta declara una función que no existe
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $declared = PlanFeature::tryFrom($feature);

        // Una clave mal escrita en la ruta es un fallo del código, no del
        // plan: que reviente en el primer test en vez de dejar la ruta
        // abierta a todos los planes.
        if ($declared === null) {
            throw new InvalidArgumentException(sprintf('plan-feature:%s no es una función de plan.', $feature));
        }

        $this->limits->assertHasFeature($declared);

        return $next($request);
    }
}
