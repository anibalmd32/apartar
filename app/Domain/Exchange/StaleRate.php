<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Currency;
use Carbon\CarbonImmutable;

/**
 * Una tasa de **más de 30 días** con la que se van a convertir bolívares
 * (`REQ-FX-006` SP-5): se permite, pero quien concilia la confirma.
 *
 * Pasa cuando la ingesta lleva semanas parada o cuando el negocio configuró su
 * tasa manual hace mucho y no la ha vuelto a tocar: la tasa «vigente» existe,
 * pero ya no dice lo que valían los bolívares el día del pago. La antigüedad
 * se mide sobre **la publicación**, como `RateSnapshot::isStale()`, no sobre
 * cuándo la leímos.
 *
 * No aplica a:
 *  - **un pago en dólares**: no se convierte nada, y `rate_applied` es `1`;
 *  - **la tasa escrita a mano al conciliar** (`ManualRate`): es justo la
 *    corrección de quien concilia, y se audita (SP-3).
 *
 * Se confirma **esa** tasa, no «cualquier tasa vieja» (`token()`): su fuente,
 * su valor y su publicación. Si cambia entre la vista previa y el envío, la
 * confirmación ya no vale y se vuelve a pedir, como el sobrante confirmado
 * por su cifra. El instante solo no basta: la ingesta guarda una fila nueva
 * cuando el valor cambia aunque la fecha publicada no, y dos fuentes pueden
 * publicar en el mismo segundo.
 */
final readonly class StaleRate
{
    /** «Más de 30 días»: a los 30 exactos todavía no lo es. */
    public const MAX_AGE_DAYS = 30;

    private function __construct(
        public RateSnapshot $snapshot,
    ) {}

    /**
     * La tasa vieja que habría que confirmar para convertir en esa moneda, o
     * `null` si no hace falta confirmar nada.
     */
    public static function in(RateSnapshot|ManualRate|null $rate, Currency $currency): ?self
    {
        if ($currency === Currency::USD || ! $rate instanceof RateSnapshot) {
            return null;
        }

        return $rate->publishedAt->addDays(self::MAX_AGE_DAYS)->isPast() ? new self($rate) : null;
    }

    /** Lo que se manda al confirmar: la fuente, el valor y la publicación de **esa** tasa. */
    public function token(): string
    {
        return sprintf('%s@%s@%d', $this->snapshot->source->value, $this->snapshot->rate, $this->snapshot->publishedAt->getTimestamp());
    }

    /** Días completos desde que se publicó. */
    public function ageInDays(): int
    {
        return (int) $this->snapshot->publishedAt->diffInDays(CarbonImmutable::now());
    }

    /**
     * @throws UnconfirmedStaleRateException si no se confirmó esta publicación
     */
    public function assertConfirmedBy(?string $token): void
    {
        if ($token !== $this->token()) {
            throw new UnconfirmedStaleRateException($this);
        }
    }

    /**
     * Para la vista previa: lo que dice el aviso y lo que se manda al
     * confirmarlo.
     *
     * @return array{token: string, days: int, sourceLabel: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token(),
            'days' => $this->ageInDays(),
            'sourceLabel' => $this->snapshot->source->label(),
        ];
    }
}
