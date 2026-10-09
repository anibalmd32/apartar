<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Un contador que el recálculo encontró descuadrado (`REQ-SUB-003` SP-1).
 *
 * `stored` es lo que decía la fila y `measured` lo que cuentan las tablas; la
 * fila ya quedó con lo segundo. Los datos de negocio no se tocan nunca: lo
 * que se corrige es la cifra, no lo contado.
 */
final readonly class UsageDrift
{
    public function __construct(
        public int $organizationId,
        public UsageMetric $metric,
        public int $stored,
        public int $measured,
    ) {}

    /**
     * Indica si la diferencia **no** la explica el reloj.
     *
     * Un contador de catálogos por encima de lo medido es lo esperado: los
     * que caducaron desde la última pasada (`UsageMetric::shrinksWithTime()`).
     * Por debajo, en cambio, es una publicación que no lo movió, y eso sí es
     * un fallo. Para el resto de métricas, cualquier diferencia lo es.
     */
    public function isUnexpected(): bool
    {
        return ! ($this->metric->shrinksWithTime() && $this->measured < $this->stored);
    }

    /**
     * @return array{organization_id: int, metric: string, stored: int, measured: int}
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'metric' => $this->metric->value,
            'stored' => $this->stored,
            'measured' => $this->measured,
        ];
    }
}
