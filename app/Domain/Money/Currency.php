<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Las dos monedas del sistema (contratos compartidos del módulo 13).
 *
 * **La deuda es siempre en USD** (`ASU-FX-04`, `REQ-FX-006` RB-1): el
 * cronograma, el total y el saldo se expresan exclusivamente en centavos de
 * dólar. `VES` existe porque el cliente **ve** precios en bolívares y **paga**
 * en bolívares, no porque haya un saldo en bolívares en ninguna parte.
 *
 * Esa asimetría es el motivo de que no haya conversión implícita en ningún
 * sitio (`REQ-FX-001` RB-3): un `add()` entre un importe en USD y otro en VES
 * no es una cuenta que haya que resolver, es un error de programa, y devolver
 * una cifra plausible en lugar de lanzar lo escondería dentro de un balance.
 *
 * No lleva símbolo ni formato: eso es de `MoneyFormatter`, que es quien conoce
 * las dos convenciones de puntuación de RB-7. Un `symbol()` aquí sería la
 * primera mitad de un formateo repartido en dos archivos.
 *
 * Espejo de `Currency` en `resources/js/types/money.ts`.
 */
enum Currency: string
{
    /** Dólar estadounidense: la moneda en la que se lleva toda la deuda. */
    case USD = 'USD';

    /** Bolívar venezolano: la moneda en la que se muestra y se paga. */
    case VES = 'VES';

    /**
     * Cómo se nombra la moneda en un selector (`T-059`: el formulario del
     * comprobante, `REQ-PAY-001` RB-2). Es un nombre, no un símbolo: el
     * importe sigue formateándolo `MoneyFormatter`, y el frontend no tiene
     * diccionario propio de monedas.
     */
    public function label(): string
    {
        return match ($this) {
            self::USD => 'Dólares (USD)',
            self::VES => 'Bolívares (VES)',
        };
    }
}
