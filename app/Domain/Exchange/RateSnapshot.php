<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Carbon\CarbonImmutable;

/**
 * Tasa concreta usada en una operación, con su procedencia y antigüedad
 * (contratos compartidos del módulo 13).
 *
 * Es el tipo que atraviesa todo el dinero del sistema: lo produce
 * `ExchangeRateProvider::fetch()`, lo devuelve `ExchangeRateService::current()`
 * y lo recibe `Money::convertTo()`. Por eso se declara antes que `Money`
 * —`T-019` va delante de `T-017`—: la dependencia es de un solo sentido, y
 * aquí no se nombra ni una vez el dinero.
 *
 * ## `rate` es un `string`, y no es una cuestión de estilo
 *
 * `REQ-FX-001` RB-1 prohíbe `FLOAT`/`DOUBLE` **en el esquema y en el código**,
 * y RB-5 convierte con `bcmul` a escala 8. Una tasa en `float` reintroduce por
 * la puerta de atrás el error de redondeo que toda la aritmética monetaria
 * está construida para evitar: no produce un fallo, produce un saldo
 * equivocado de un céntimo que nadie ve hasta que un cliente reclama.
 *
 * ## Dos fechas porque son dos preguntas distintas
 *
 * `publishedAt` es cuándo publicó la fuente esa tasa; `fetchedAt`, cuándo la
 * leímos nosotros (`REQ-FX-002` RB-3b). Tener las dos es lo que permite
 * distinguir «la tasa es vieja» de «nuestra ingesta está parada», y medir la
 * obsolescencia sobre la primera es el motivo entero de que la segunda exista
 * por separado.
 */
final readonly class RateSnapshot
{
    public function __construct(
        /** Decimal en `string`: nunca `float`. */
        public string $rate,
        public RateSource $source,
        /** Cuándo la publicó la fuente (BCV). Es la antigüedad que le importa al negocio. */
        public CarbonImmutable $publishedAt,
        /** Cuándo la leímos nosotros. Solo sirve para diagnosticar la ingesta. */
        public CarbonImmutable $fetchedAt,
    ) {}

    /**
     * Obsolescencia medida sobre `publishedAt`, **no** sobre `fetchedAt`.
     *
     * La diferencia no es sutil: medir sobre `fetchedAt` haría que una tasa
     * publicada hace una semana pareciera fresca por haberla releído hace un
     * minuto —el job corre cada 30 minutos aunque la fuente lleve días sin
     * moverse (`REQ-FX-002` RB-3)—, y el aviso de `REQ-FX-005` RB-3 no se
     * encendería nunca. Al cliente le importa cuándo el BCV publicó la tasa;
     * cuándo corrió nuestro job es asunto nuestro.
     *
     * A las 24 h exactas todavía no es obsoleta: RB-3 dice «más de 24 h».
     */
    public function isStale(int $maxAgeHours = 24): bool
    {
        return $this->publishedAt->addHours($maxAgeHours)->isPast();
    }
}
