<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;

/**
 * Una clave de idempotencia que ya registró **otro** pago (`REQ-PAY-007`
 * SP-5, `T-062`).
 *
 * La clave existe para que un doble clic no registre dos veces el mismo
 * pago: el segundo envío, idéntico, recibe el pago que ya existe. Pero si la
 * clave llega con otro apartado, otro importe u otra moneda, no es un doble
 * clic, es otro pago con una clave vieja —un formulario que no se renovó, o
 * una petición compuesta a mano—, y devolver el pago anterior diría «pago
 * registrado» de un pago que no se registró. Se rechaza y se pide recargar.
 */
final class IdempotencyKeyReusedException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct()
    {
        parent::__construct('Este formulario ya registró otro pago. Recarga la página para registrar uno nuevo.');
    }
}
