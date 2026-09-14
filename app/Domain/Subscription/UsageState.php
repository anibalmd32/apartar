<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Lo consumido de una métrica frente a su techo (`REQ-SUB-003` RB-6).
 *
 * Existe para que la interfaz no tenga que dividir nada: `REQ-SUB-004` RB-1
 * dice que **el frontend nunca decide, solo refleja**, y el sitio donde esa
 * regla se rompe siempre es el mismo —un `used >= limit` escrito en una
 * pantalla, y otro distinto tres pantallas más allá—.
 *
 * **`limit` a `null` es ilimitado**, nunca cero ni «sin definir»
 * (`REQ-SUB-001` SP-2). De ahí que `percentage` sea `null` sin techo: un `0`
 * pintaría una barra vacía que miente sobre un plan sin límite, y un `100` una
 * llena que miente en la dirección contraria.
 *
 * Espejo exacto de `UsageState` en `resources/js/types/plan.ts`.
 */
final readonly class UsageState
{
    private function __construct(
        public UsageMetric $metric,
        public int $used,
        /** `null` = ilimitado. */
        public ?int $limit,
        public bool $isAtLimit,
        /** `null` cuando no hay techo contra el que medir. */
        public ?int $percentage,
    ) {}

    /**
     * Estado derivado de lo consumido y del techo del plan.
     */
    public static function of(UsageMetric $metric, int $used, ?int $limit): self
    {
        return new self(
            metric: $metric,
            used: $used,
            limit: $limit,
            isAtLimit: $limit !== null && $used >= $limit,
            percentage: self::percentageOf($used, $limit),
        );
    }

    /**
     * Indica si queda sitio para `$amount` unidades más.
     */
    public function admits(int $amount): bool
    {
        return $this->limit === null || $this->used + $amount <= $this->limit;
    }

    /**
     * Unidades que quedan, o `null` si no hay techo.
     *
     * Acotado a cero: un consumo por encima del techo es lo que deja una
     * bajada de plan (`REQ-SUB-008` RB-4), y ahí lo que queda es nada, no una
     * cifra negativa que después haya que interpretar.
     */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /**
     * Indica si conviene avisar sin bloquear (`REQ-SUB-003` RB-7).
     */
    public function deservesWarning(): bool
    {
        return $this->percentage !== null && $this->percentage >= 80;
    }

    /**
     * Forma con la que viaja a las page props.
     *
     * @return array{used: int, limit: int|null, isAtLimit: bool, percentage: int|null}
     */
    public function toArray(): array
    {
        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'isAtLimit' => $this->isAtLimit,
            'percentage' => $this->percentage,
        ];
    }

    /**
     * Porcentaje consumido, o `null` si no hay techo.
     *
     * Un techo de cero tampoco da porcentaje: dividir por él no significa
     * «todo consumido», significa que el plan no admite ninguno.
     */
    private static function percentageOf(int $used, ?int $limit): ?int
    {
        if ($limit === null || $limit <= 0) {
            return null;
        }

        return (int) min(100, round($used / $limit * 100));
    }
}
