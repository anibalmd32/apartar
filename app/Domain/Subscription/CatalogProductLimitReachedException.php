<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

final class CatalogProductLimitReachedException extends RuntimeException
{
    public const STATUS = 422;

    public const KEY = 'products_per_catalog';

    private function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly int $requested,
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

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
            'requested' => $this->requested,
            'excess' => $this->requested - $this->limit,
            'suggestion' => 'También puedes quitar del catálogo un producto que ya no quieras enseñar.',
        ];
    }
}
