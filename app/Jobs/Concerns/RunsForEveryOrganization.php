<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;

/**
 * Recorre las organizaciones fijando el tenant **por elemento**
 * (`REQ-INF-003` RB-1b).
 *
 * Es el otro mecanismo, el de los siete jobs programados que no trabajan sobre
 * una organización sino sobre todas: `ExpireLayawaysJob`,
 * `DetectOverdueLayawaysJob`, `DispatchReminders`, `ReconcileLayaways`,
 * `ProcessTelemetryBatch`, `RecalculateUsage` y `DispatchCatalogDigests`.
 *
 * Lo que RB-1b prohíbe es el atajo evidente: fijar **una** organización para
 * toda la ejecución, o peor, correr sin tenant y filtrar a mano. Un job que
 * recorre 300 negocios no tiene *un* tenant; tiene 300, uno detrás de otro, y
 * cada uno tiene que quedar limpio antes del siguiente.
 *
 * La enumeración sí ocurre sin filtro —`organizations` no es una tabla del
 * tenant, así que basta consultarla— y es el único punto del recorrido en que
 * el job ve más de una organización a la vez.
 *
 * @see RunsInTenantContext para el job de una sola organización
 */
trait RunsForEveryOrganization
{
    /**
     * Ejecuta `$callback` una vez por organización, con el tenant fijado en
     * cada pasada y restaurado al salir de ella.
     *
     * Si una pasada lanza, la excepción se propaga y el job falla: el contexto
     * queda limpio igualmente —de eso se ocupa `runFor()`— y los comandos
     * programados son idempotentes por diseño (`REQ-INF-003` RB-6), así que el
     * siguiente ciclo recupera lo que quedó a medias. Tragarse el fallo para
     * seguir con la organización siguiente escondería justo lo que hay que
     * mirar.
     *
     * @param  Closure(Organization): void  $callback
     */
    protected function forEachOrganization(Closure $callback): void
    {
        $context = app(TenantContext::class);

        foreach ($this->organizationsToVisit() as $organization) {
            $context->runFor($organization, static function () use ($callback, $organization): void {
                $callback($organization);
            });
        }
    }

    /**
     * Organizaciones que el job recorre.
     *
     * Por defecto, todas las que no están dadas de baja. Un job con otro
     * criterio —solo las suspendidas, solo las de un huso horario concreto
     * para los comandos ⏰ de `REQ-INF-003` RB-5— lo sobrescribe.
     *
     * @return iterable<int, Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        return Organization::query()->orderBy('id')->cursor();
    }
}
