<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;

/**
 * Siembra una organización "vecina" y sitúa el escenario en un tenant.
 *
 * `REQ-QAX-003` RB-10: **todo test de feature crea al menos dos
 * organizaciones**. Es la regla que hace que las fugas se detecten por
 * accidente y no solo cuando alguien las busca: con un único tenant en la
 * base, un `where` olvidado pasa desapercibido porque todo lo que devuelve la
 * consulta resulta ser, por casualidad, del tenant correcto.
 *
 * La segunda mitad —situarse en una organización— llega con `T-005`: sin
 * `TenantContext` el helper podía crear dos tenants pero no ponerse en
 * ninguno, y una consulta del tenant fuera de contexto ni siquiera se ejecuta.
 */
trait CreatesNeighbourOrganization
{
    /**
     * Organización vecina del escenario, creada una sola vez por test.
     */
    private ?Organization $neighbour = null;

    /**
     * Crea (o devuelve) la organización vecina del escenario.
     *
     * Llamarlo dos veces en el mismo test devuelve la misma: el escenario
     * necesita *una* vecina, no una por aserción.
     *
     * @param  array<string, mixed>  $attributes  atributos que sobrescriben los de la factory
     */
    protected function neighbourOrganization(array $attributes = []): Organization
    {
        return $this->neighbour ??= Organization::factory()->create($attributes);
    }

    /**
     * Crea una organización vecina adicional, siempre nueva.
     *
     * Para los escenarios que necesitan comprobar que el aislamiento aguanta
     * con más de dos tenants.
     *
     * @param  array<string, mixed>  $attributes  atributos que sobrescriben los de la factory
     */
    protected function createNeighbourOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    /**
     * Indica si el escenario tiene ya más de una organización sembrada.
     *
     * Pensado para que un test de aislamiento afirme su propia precondición:
     * un test de fuga cruzada con un solo tenant no prueba nada, y pasa.
     */
    protected function hasNeighbourOrganization(): bool
    {
        return Organization::query()->count() > 1;
    }

    /**
     * Sitúa el resto del test dentro de esa organización.
     *
     * Equivale a lo que hace el middleware de resolución en una petición real:
     * a partir de aquí las consultas del tenant filtran por ella y las
     * creaciones la rellenan.
     */
    protected function actingAsOrganization(Organization $organization): static
    {
        $context = app(TenantContext::class);

        $context->forget();
        $context->setOrganization($organization);

        return $this;
    }

    /**
     * Ejecuta `$callback` dentro de esa organización y restaura el contexto
     * anterior al salir, aunque el callback lance.
     *
     * Es la forma de escribir un test de fuga cruzada sin que el propio test
     * se convierta en la fuga: sembrar en A, volver a B y comprobar que desde
     * B no se ve nada de A.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withinOrganization(Organization $organization, Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($organization, $callback);
    }

    /**
     * Deja el escenario sin organización activa.
     *
     * Lo que ve un job que se olvidó de fijar su tenant (`REQ-ORG-002` SP-3) y
     * lo que hace el `finally` de todo job (SP-7).
     */
    protected function withoutOrganization(): static
    {
        app(TenantContext::class)->forget();

        return $this;
    }
}
