<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Organization;

/**
 * Siembra una organización "vecina" junto a la del escenario bajo prueba.
 *
 * `REQ-QAX-003` RB-10: **todo test de feature crea al menos dos
 * organizaciones**. Es la regla que hace que las fugas de tenant se detecten
 * por accidente y no solo cuando alguien las busca: con un único tenant en la
 * base, un `where` olvidado pasa desapercibido porque todo lo que devuelve la
 * query resulta ser, por casualidad, del tenant correcto.
 *
 * **Lo que falta:** fijar el tenant activo y ejecutar un bloque dentro de la
 * vecina exigen `TenantContext` (`T-005`); los tests de fuga cruzada que
 * consumen este helper son `T-007`. Lo que ya funciona —crear la vecina con
 * datos equivalentes y comprobar que el escenario tiene dos— es lo que esta
 * versión entrega.
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
        return Organization::query()->withoutGlobalScopes()->count() > 1;
    }
}
