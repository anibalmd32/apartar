<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Cómo se escribe un importe para una persona (`REQ-FX-001` RB-7).
 *
 * **Las dos monedas se puntúan al revés la una de la otra**, y esa es toda la
 * razón de que esta clase exista aparte de `Money`: USD va `$1,234.56` —coma
 * de miles, punto decimal— y VES va `Bs. 1.234,56` —punto de miles, coma
 * decimal—, conforme a la convención local. Un formateo escrito a mano en
 * cualquier pantalla acierta con una y se equivoca con la otra, y el resultado
 * no es un error: es una cifra legible que significa otra cosa.
 *
 * Por eso el formateo vive **en el servidor** y viaja resuelto en `MoneyView`
 * (RB-6). El frontend no reimplementa la conversión ni el formateo: solo elige
 * cuál de las dos representaciones enseña, según el toggle de `REQ-FX-004`.
 *
 * Es estática y sin estado a propósito: no hay nada que configurar por
 * instancia, y un formateador inyectable invitaría a que alguien inyectara
 * otro con otras reglas, que es exactamente la segunda convención que esto
 * viene a impedir.
 */
final class MoneyFormatter
{
    /**
     * Escribe el importe con la puntuación de su moneda (RB-7).
     *
     * USD `$1,234.56`; VES `Bs. 1.234,56`. Los negativos existen —son el
     * crédito de `REQ-FX-001` SP-3— y se escriben con el signo delante de
     * todo, `-$20.00` y `-Bs. 850,00`, que es como se lee una devolución.
     *
     * ## Ni `NumberFormatter` ni `number_format()`
     *
     * El primero depende del *locale* del sistema y de la versión de ICU
     * instalada: el mismo código escribiría el dinero distinto en el
     * contenedor y en la máquina de alguien, y nadie habría tocado nada.
     * El segundo recibe un `float`, que es la puerta que el resto de esta
     * clase lleva cerrada desde el principio.
     *
     * Así que los separadores se ponen sobre la cadena de dígitos. Es más
     * código y no depende de nada: la salida de esta función es la misma en
     * cualquier entorno y en cualquier versión, que para el sitio donde se
     * escribe el precio de algo es exactamente lo que hace falta.
     */
    public static function format(Money $money): string
    {
        // El valor absoluto se compone aparte y el signo se antepone al final:
        // agruparlo con el signo dentro metería el separador de millar en el
        // sitio equivocado en cuanto el importe tuviera cuatro cifras.
        $absolute = abs($money->cents);
        $units = intdiv($absolute, 100);
        $fraction = $absolute % 100;
        $sign = $money->cents < 0 ? '-' : '';

        return match ($money->currency) {
            Currency::USD => sprintf('%s$%s.%02d', $sign, self::group($units, ','), $fraction),
            Currency::VES => sprintf('%sBs. %s,%02d', $sign, self::group($units, '.'), $fraction),
        };
    }

    /**
     * Agrupa los dígitos de tres en tres, desde la derecha.
     *
     * Se invierte la cadena, se parte en grupos de tres y se vuelve a
     * invertir: así el grupo corto queda a la izquierda —`1,234,567` y no
     * `123,456,7`— sin tener que calcular el resto de la división.
     */
    private static function group(int $units, string $separator): string
    {
        $digits = (string) $units;

        return strrev(implode($separator, str_split(strrev($digits), 3)));
    }
}
