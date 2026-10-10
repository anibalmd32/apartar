<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

/**
 * El catálogo ya tiene todos los productos que el plan admite en uno
 * (`max_products_per_catalog`, `REQ-SUB-004` RB-3, `REQ-CAT-023` RB-6 y SP-3).
 *
 * Es la hermana de `PlanLimitReachedException` para el único techo que **no**
 * es una `UsageMetric`: se cuenta por catálogo y no por organización (ver el
 * docblock de `UsageMetric`), así que no tiene contador ni fila que bloquear.
 * Lleva lo mismo que su hermana —lo que hay, el techo, cuántos se pedían y el
 * plan que lo resuelve— y se responde igual, con `422`: no falta permiso,
 * falta sitio.
 */
final class CatalogProductLimitReachedException extends RuntimeException
{
    public const STATUS = 422;

    /** La clave con la que la interfaz lo distingue de las seis métricas. */
    public const KEY = 'products_per_catalog';

    private function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly int $requested,
        /** `null` si ningún plan público lo admite. */
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  int  $used  productos que el catálogo tiene hoy
     * @param  int  $requested  los que tendría con este cambio
     */
    public static function of(int $used, int $limit, int $requested, Plan $current, ?Plan $minimum): self
    {
        $message = sprintf(
            'Tu plan %s permite %d %s por catálogo, y este cambio lo dejaría con %d.',
            $current->name,
            $limit,
            $limit === 1 ? 'producto' : 'productos',
            $requested,
        );

        if ($minimum instanceof Plan) {
            $ceiling = $minimum->maxProductsPerCatalog();
            $message .= sprintf(' %s permite %s.', $minimum->name, $ceiling === null ? 'sin límite' : $ceiling.' por catálogo');
        } else {
            $message .= ' No hay ningún plan con un techo mayor.';
        }

        return new self($used, $limit, $requested, $minimum, $message);
    }

    /**
     * El mismo cuerpo que `PlanLimitReachedException::toArray()`, con la clave
     * del techo en lugar de una métrica.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => self::KEY,
            'used' => $this->used,
            'limit' => $this->limit,
            'minimumPlan' => $this->minimumPlan?->code,
            'minimumPlanName' => $this->minimumPlan?->name,
            // `REQ-CAT-023` SP-3 · el exceso exacto, para decir «quita 2».
            'requested' => $this->requested,
            'excess' => $this->requested - $this->limit,
            'suggestion' => 'También puedes quitar del catálogo un producto que ya no quieras enseñar.',
        ];
    }
}
