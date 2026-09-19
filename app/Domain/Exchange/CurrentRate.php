<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

/**
 * La tasa de **esta** respuesta, resuelta una sola vez
 * (`REQ-FX-004`, `REQ-FX-005`).
 *
 * ## Por qué existe, si `ExchangeRateService` ya la resuelve
 *
 * Porque en una misma página la tasa se usa **dos veces y para dos cosas**: la
 * pinta el sello —«Tasa BCV del 18/09 09:00»— y la multiplica cada importe que
 * se proyecta a bolívares. Si el sello sale de una consulta y los importes de
 * otra, basta que el job de ingesta inserte entre las dos para que la página
 * estampe una tasa distinta de la que multiplicó.
 *
 * Y ese es el fallo más caro de todos, porque **el número es creíble**: nadie
 * revisa un precio que parece correcto. No hay excepción, ni error, ni entrada
 * de log; hay un cliente que multiplica a mano y no le da.
 *
 * Con esto, la respuesta resuelve la tasa la primera vez que alguien la pide y
 * todos los demás leen esa. Una resolución por respuesta, por construcción.
 *
 * ## `scoped()` y no `singleton()`
 *
 * El memo tiene que **morir con la petición**. Un `singleton()` sobrevive en
 * el worker de colas, que es un proceso de vida larga, y serviría la tasa de
 * hace media hora en el job siguiente —para siempre, y sin que nada fallara ni
 * apareciera en ningún log—. Es exactamente la trampa que `REQ-FX-003` RB-5b
 * dejó escrita después de que `T-020` se la encontrara con el binding del
 * proveedor. Laravel limpia los *scoped* entre peticiones y entre jobs, que es
 * justo la vida que este memo tiene que tener.
 */
final class CurrentRate
{
    /**
     * Qué organización se resolvió, para no servir la tasa de otra si el
     * contexto cambiara.
     *
     * `TenantContext` ya impide cambiar de organización dentro de una
     * petición, así que esto no debería dispararse nunca. Está porque el coste
     * de comprobarlo es una comparación y el coste de equivocarse es proyectar
     * el catálogo de un negocio con la tasa del vecino.
     */
    private ?int $resolvedFor = null;

    private bool $resolved = false;

    private ?RateSnapshot $snapshot = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * La tasa vigente de la organización en contexto, o `null` si no hay
     * ninguna.
     *
     * `null` no es un error: es el estado degradado de `REQ-FX-005` RB-4, en
     * el que la tienda sigue vendiendo en dólares.
     */
    public function snapshot(): ?RateSnapshot
    {
        $organization = $this->organization();
        $organizationId = $organization?->getKey() === null ? null : (int) $organization->getKey();

        if ($this->resolved && $this->resolvedFor === $organizationId) {
            return $this->snapshot;
        }

        $this->snapshot = $this->rates->current($organization);
        $this->resolvedFor = $organizationId;
        $this->resolved = true;

        return $this->snapshot;
    }

    /**
     * La misma tasa, en la forma en que viaja al cliente.
     *
     * Sale del mismo `snapshot()` que usa la proyección de los importes, que
     * es lo que garantiza que el sello y las cifras de una página no puedan
     * contradecirse.
     */
    public function view(): RateView
    {
        return RateView::of($this->snapshot(), $this->source());
    }

    /**
     * Qué fuente se está usando, también cuando no hay tasa.
     *
     * Sin tasa el sello no se pinta, pero el toggle deshabilitado sí necesita
     * decir de qué fuente no hay cifra, así que la fuente se resuelve aparte
     * del `snapshot()`.
     */
    private function source(): RateSource
    {
        $organization = $this->organization();

        return $organization === null
            ? RateSource::Bcv
            : $this->rates->sourceFor($organization);
    }

    /**
     * La organización en contexto, o `null` fuera de un tenant.
     *
     * Marketing, el acceso y el feed multi-negocio no tienen una: ahí se
     * contesta con la fuente por defecto de la plataforma en lugar de dejar
     * que el contexto lance (`REQ-FX-004` RB-5).
     */
    private function organization(): ?Organization
    {
        return $this->context->hasOrganization() ? $this->context->organization() : null;
    }
}
