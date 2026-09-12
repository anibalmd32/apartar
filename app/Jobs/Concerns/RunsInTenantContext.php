<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Fija el tenant de un job desde su payload y lo limpia al terminar
 * (`REQ-ORG-002` RB-5 y SP-7, `REQ-INF-003` RB-1b).
 *
 * **Un job nunca hereda el tenant del proceso que lo encoló.** Suena
 * redundante —"si lo encolé desde la organización A, ¿no va para A?"— y no lo
 * es: entre encolar y ejecutar pasan minutos, el worker es otro proceso que ya
 * despachó otros jobs, y el estado del encolador no viaja. Lo único que un job
 * podría "heredar" es lo que dejó puesto el job anterior.
 *
 * De ahí el `finally`. Una cola compartida procesa jobs de dos organizaciones
 * seguidas; si el primero lanza y no limpia, el segundo lee y escribe en la
 * organización equivocada **sin ningún error visible**. Es la fuga más difícil
 * de detectar del diseño, porque solo aparece cuando algo ya había fallado
 * antes.
 *
 * ## Cuál de los dos mecanismos usa cada job
 *
 * - **Un job que trabaja sobre una organización** usa este trait: su `handle()`
 *   es una sola llamada a `runInTenantContext()`.
 * - **Un job que recorre todas** (`ExpireLayawaysJob`, `DetectOverdueLayawaysJob`,
 *   `DispatchReminders`, `ReconcileLayaways`, `ProcessTelemetryBatch`,
 *   `RecalculateUsage`, `DispatchCatalogDigests`) usa
 *   {@see RunsForEveryOrganization}: enumera sin filtro y fija el tenant **por
 *   elemento**, nunca uno solo para toda la ejecución.
 *
 * No hay un tercer camino. Fijar el tenant a mano en un `handle()` se salta el
 * `finally` y es justo lo que SP-7 prohíbe.
 */
trait RunsInTenantContext
{
    /**
     * Organización sobre la que trabaja el job.
     *
     * Viaja en el payload —normalmente como propiedad promovida `readonly` del
     * constructor— porque es lo único que sobrevive a la serialización.
     */
    abstract public function tenantOrganizationId(): int;

    /**
     * Ejecuta el cuerpo del job con su organización fijada.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws ModelNotFoundException si el payload trae una organización que ya no existe
     */
    protected function runInTenantContext(Closure $callback): mixed
    {
        $organization = app(TenantResolver::class)->forOrganizationId($this->tenantOrganizationId());

        $context = app(TenantContext::class);

        // El worker es un proceso de vida larga: lo que hubiera antes es
        // residuo del job anterior, no contexto de este.
        $context->forget();
        $context->setOrganization($organization);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
