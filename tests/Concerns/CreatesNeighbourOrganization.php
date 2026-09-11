<?php

declare(strict_types=1);

namespace Tests\Concerns;

use RuntimeException;

/**
 * Siembra una organización "vecina" junto a la del escenario bajo prueba.
 *
 * `REQ-QAX-003` RB-10: **todo test de feature crea al menos dos
 * organizaciones**. Es la regla que hace que las fugas de tenant se detecten
 * por accidente y no solo cuando alguien las busca: con un único tenant en la
 * base, un `where` olvidado pasa desapercibido porque todo lo que devuelve la
 * query resulta ser, por casualidad, del tenant correcto.
 *
 * El estado de este helper es deliberadamente parcial: `T-003` instala el
 * contrato y `T-005` (`TenantContext`) más `T-007` (gate de aislamiento) lo
 * completan, cuando existan el modelo `Organization` y su factory. Hasta
 * entonces cualquier uso falla con un mensaje explícito en lugar de devolver
 * un escenario de un solo tenant en silencio.
 */
trait CreatesNeighbourOrganization
{
    /**
     * Clase del modelo de organización. No se referencia por `::class` a
     * propósito: todavía no existe y el análisis estático debe seguir limpio.
     */
    private const ORGANIZATION_MODEL = 'App\\Models\\Organization';

    /**
     * Crea una organización vecina con datos equivalentes a los del escenario.
     *
     * @param  array<string, mixed>  $attributes  atributos que sobrescriben los de la factory
     * @return object la organización vecina recién creada
     *
     * @throws RuntimeException mientras el modelo `Organization` no exista (T-005)
     */
    protected function createNeighbourOrganization(array $attributes = []): object
    {
        $model = self::ORGANIZATION_MODEL;

        if (! class_exists($model)) {
            throw new RuntimeException(
                'CreatesNeighbourOrganization necesita el modelo Organization, que llega con T-005. '
                .'Hasta entonces ningún test puede declarar que cubre el aislamiento entre organizaciones.'
            );
        }

        /** @var object $organization */
        $organization = $model::factory()->create($attributes);

        return $organization;
    }

    /**
     * Indica si el escenario ya tiene más de una organización sembrada.
     *
     * Pensado para que los tests de aislamiento afirmen su propia
     * precondición: un test de fuga cruzada con un solo tenant no prueba nada.
     *
     * @throws RuntimeException mientras el modelo `Organization` no exista (T-005)
     */
    protected function hasNeighbourOrganization(): bool
    {
        $model = self::ORGANIZATION_MODEL;

        if (! class_exists($model)) {
            throw new RuntimeException(
                'CreatesNeighbourOrganization necesita el modelo Organization, que llega con T-005.'
            );
        }

        return $model::query()->count() > 1;
    }
}
