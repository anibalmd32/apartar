<?php

declare(strict_types=1);

namespace App\Domain\Money;

use LogicException;

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
     * crédito de `REQ-FX-001` SP-3— y tienen que poder escribirse.
     *
     * @throws LogicException mientras `T-018` no escriba el formateo
     */
    public static function format(Money $money): string
    {
        throw new LogicException(sprintf(
            'MoneyFormatter::format() está declarado y sin implementar: es de `T-018`, que lo cierra con la '
            .'batería de formateo de `REQ-FX-001` RB-7. Se pidió escribir %d céntimos de %s. Las dos '
            .'convenciones son opuestas —USD $1,234.56 y VES Bs. 1.234,56— y tienen que salir exactas: una cifra '
            .'con los separadores cambiados no parece un error, parece otro precio.',
            $money->cents,
            $money->currency->value,
        ));
    }
}
