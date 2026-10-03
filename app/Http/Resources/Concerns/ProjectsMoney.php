<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

/**
 * Compone el `MoneyView` con el que un importe sale al cliente
 * (`REQ-FX-001` RB-6, `REQ-FX-005` RB-4).
 *
 * **Es el único sitio por el que un importe cruza al frontend**, y eso es lo
 * que hace que las dos reglas de presentación del dinero se puedan sostener:
 *
 *  - **El formateo es del servidor.** Las dos monedas se puntúan al revés la
 *    una de la otra —`$1,234.56` y `Bs. 1.234,56`— y un `.toFixed(2)` escrito
 *    en cualquier `.tsx` produce una cifra que se lee como un precio correcto
 *    y no lo es. Aquí se formatean las dos, con `MoneyFormatter`, y el cliente
 *    solo elige cuál enseña primero.
 *  - **Sin tasa, el bolívar no aparece de ninguna forma.** `ves` es `null`, y
 *    `null` no es «cero» ni «todavía no»: es que no hay con qué proyectar.
 *    `REQ-FX-005` RB-4 prohíbe expresamente `Bs. 0,00` y el guion, porque los
 *    dos ponen en pantalla un precio que nadie ha calculado.
 *
 * ## La tasa se pide, no se recibe
 *
 * Y se pide a `CurrentRate`, que la resuelve **una vez por respuesta**. Es
 * deliberado: el sello de la página sale del mismo sitio, así que las cifras y
 * la tasa que dice haberlas producido no pueden contradecirse ni aunque el job
 * de ingesta inserte a mitad de la petición.
 *
 * ## Una tasa vieja **sí** proyecta
 *
 * `isStale` es un aviso, no un bloqueo (`REQ-FX-005` RB-3). Con la tasa de
 * hace treinta horas el importe se muestra igual y lo que cambia es la
 * advertencia que acompaña al sello. Dejar de proyectar por antigüedad
 * violaría RB-1, que es la regla que sostiene el requisito entero: la tienda
 * sigue vendiendo.
 */
trait ProjectsMoney
{
    /**
     * El importe en las dos monedas, listo para pintar.
     *
     * @return array{cents: int, usd: string, ves: string|null}
     */
    protected function money(Money $amount): array
    {
        return [
            'cents' => $amount->cents,
            'usd' => MoneyFormatter::format($amount),
            'ves' => $this->projectedToVes($amount),
        ];
    }

    /**
     * Un importe hecho de partes de **varios negocios**, cada una proyectada
     * con la tasa de **su** negocio (`REQ-LAY-020` RB-7).
     *
     * En dólares es la suma; en bolívares, la suma de cada parte convertida con
     * su tasa —lo que de verdad costaría pagarlo todo hoy—, nunca el total
     * convertido con la tasa de uno de ellos. Si a alguna parte le falta la
     * tasa, el total no se proyecta: una suma con un hueco sería una cifra que
     * nadie calculó (`REQ-FX-005` RB-4).
     *
     * @param  list<array{organization: Organization, amount: Money}>  $parts
     * @return array{cents: int, usd: string, ves: string|null}
     */
    protected function moneyAcrossBusinesses(array $parts): array
    {
        $usd = new Money(0);
        $ves = new Money(0, Currency::VES);

        foreach ($parts as $part) {
            $usd = $usd->add($part['amount']);
            $rate = app(TenantContext::class)->runFor($part['organization'], fn (): ?RateSnapshot => $this->currentRate()->snapshot());
            $ves = $rate === null || $ves === null ? null : $ves->add($part['amount']->convertTo(Currency::VES, $rate));
        }

        return [
            'cents' => $usd->cents,
            'usd' => MoneyFormatter::format($usd),
            'ves' => $ves === null ? null : MoneyFormatter::format($ves),
        ];
    }

    /**
     * El mismo importe en bolívares, o `null` si no hay tasa con la que
     * proyectarlo.
     *
     * La conversión va por `Money::convertTo()` —`bcmul` a escala 8 y un solo
     * redondeo al final— y no por una multiplicación escrita aquí: `REQ-FX-001`
     * es explícito en que ningún otro módulo implementa conversión por su
     * cuenta.
     */
    private function projectedToVes(Money $amount): ?string
    {
        $rate = $this->currentRate()->snapshot();

        if ($rate === null) {
            return null;
        }

        return MoneyFormatter::format($amount->convertTo(Currency::VES, $rate));
    }

    /**
     * La tasa de esta respuesta.
     *
     * Se resuelve del contenedor y no por constructor porque quien usa este
     * trait son *resources* de Eloquent, que Laravel instancia con el modelo y
     * nada más.
     */
    private function currentRate(): CurrentRate
    {
        return app(CurrentRate::class);
    }
}
