<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * Organización activa de la petición o del job.
 *
 * Singleton por ciclo de vida. Lo fija un middleware en la web
 * (`ResolveTenant`, `ResolveCatalogTenant`, `ResolvePortalTenant`) o el propio
 * job en las colas, desde el tenant que viaja en su payload
 * (`REQ-ORG-002` RB-4 y RB-5).
 *
 * **Invariante:** ninguna otra clase escribe `organization_id` a mano. Quien
 * lo hace es `BelongsToOrganization`, leyendo de aquí.
 *
 * Dos reglas que parecen rígidas y no lo son:
 *
 * - Cambiar de organización a mitad de camino **lanza** (SP-6). Significa que
 *   algo se resolvió mal antes y que las consultas ya ejecutadas salieron con
 *   el tenant anterior.
 * - `forget()` es **obligatorio en el `finally` de todo job** (SP-7). Una cola
 *   compartida procesa jobs de dos organizaciones seguidas; sin limpiar, el
 *   segundo hereda el tenant del primero.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    /**
     * Organización activa.
     *
     * @throws MissingTenantContextException si no hay ninguna fijada
     */
    public function organization(): Organization
    {
        if ($this->organization === null) {
            throw MissingTenantContextException::whileReading();
        }

        return $this->organization;
    }

    /**
     * Identificador de la organización activa.
     *
     * @throws MissingTenantContextException si no hay ninguna fijada
     */
    public function organizationId(): int
    {
        return (int) $this->organization()->getKey();
    }

    public function hasOrganization(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Fija la organización activa.
     *
     * Idempotente para la misma organización.
     *
     * @throws TenantAlreadySetException si ya hay otra fijada
     */
    public function setOrganization(Organization $organization): void
    {
        if ($this->organization !== null) {
            if ($this->organization->is($organization)) {
                return;
            }

            throw TenantAlreadySetException::cannotSwitch(
                (int) $this->organization->getKey(),
                (int) $organization->getKey(),
            );
        }

        $this->organization = $organization;
    }

    /**
     * Olvida la organización activa.
     *
     * Obligatorio en el `finally` de todo job (SP-7).
     */
    public function forget(): void
    {
        $this->organization = null;
    }

    /**
     * Ejecuta `$callback` con esa organización fijada y restaura el estado
     * anterior al salir.
     *
     * Es la única vía legítima para cambiar de tenant dentro de un mismo
     * ciclo de vida, y la que usan los comandos que recorren todas las
     * organizaciones (`REQ-INF-003` RB-1b). La restauración ocurre también
     * **si el callback lanza**: de lo contrario una excepción a mitad de un
     * lote dejaría al resto del proceso operando con el tenant equivocado.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;

        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
