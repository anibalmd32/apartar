<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

/**
 * La tasa vigente tal como se pinta (contratos compartidos del módulo 13).
 *
 * Espejo exacto de `RateView` en `resources/js/types/exchange.ts`. Viaja en las
 * page props de toda página con precios y la refresca el endpoint interno
 * `GET /api/tasa?source=` cada cinco minutos (RB-5): **el frontend nunca llama
 * a la API externa**.
 *
 * ## La fecha que viaja es `publishedAt`, no `fetchedAt`
 *
 * Es la corrección que trajo `T-019` al contrato. El sello de `REQ-FX-005`
 * RB-2 —«Tasa BCV del DD/MM HH:mm»— dice cuándo **publicó** la fuente, que es
 * la antigüedad que le importa al negocio; con `fetchedAt`, un fin de semana
 * sin publicación estamparía «hoy 09:00» sobre una tasa del viernes. Cuándo
 * corrió nuestro job es asunto nuestro y se diagnostica en el servidor.
 *
 * ## `rate` es el único número en coma flotante de todo el sistema
 *
 * Y está aquí, en el borde de salida, porque el contrato de TypeScript dice
 * `number | null`. No se opera con él: los importes llegan ya proyectados y
 * formateados en `MoneyView` (`REQ-FX-001` RB-6), así que esta cifra solo se
 * pinta en el sello. La aritmética exacta se queda del lado donde hay `bcmul`.
 *
 * `null` significa **que no hay ninguna tasa**, y no cero: la interfaz oculta
 * entonces el monto en bolívares por completo y deshabilita el toggle
 * (`REQ-FX-005` RB-4).
 */
final readonly class RateView
{
    private function __construct(
        public ?float $rate,
        public RateSource $source,
        public ?string $publishedAt,
        public bool $isStale,
    ) {}

    /**
     * La tasa que hay, o la ausencia de tasa para esa fuente.
     */
    public static function of(?RateSnapshot $snapshot, RateSource $source): self
    {
        if ($snapshot === null) {
            return new self(null, $source, null, false);
        }

        return new self(
            rate: (float) $snapshot->rate,
            source: $snapshot->source,
            publishedAt: $snapshot->publishedAt->toIso8601String(),
            isStale: $snapshot->isStale(),
        );
    }

    /**
     * Forma con la que viaja a las page props y al endpoint.
     *
     * **`sourceLabel` viaja resuelto desde el enum** y no se compone en la
     * pantalla, por lo mismo que `PaymentAccountView` lleva su `methodLabel`:
     * el sello del catálogo y el selector de ajustes hablan de las mismas tres
     * fuentes, y un diccionario por pantalla acaba llamándolas distinto. El
     * nombre lo dice `RateSource::label()`, una sola vez.
     *
     * @return array{rate: float|null, source: string, sourceLabel: string, publishedAt: string|null, isStale: bool}
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'source' => $this->source->value,
            'sourceLabel' => $this->source->label(),
            'publishedAt' => $this->publishedAt,
            'isStale' => $this->isStale,
        ];
    }
}
