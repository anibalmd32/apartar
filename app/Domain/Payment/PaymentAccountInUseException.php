<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;
use RuntimeException;

/**
 * Se pidió borrar una cuenta de cobro que tiene comprobantes, así que
 * **quedó desactivada en su lugar** (`REQ-ORG-007` RB-6, SP-3).
 *
 * ## No es un fallo, y aun así es una excepción
 *
 * La operación se completó: la cuenta está desactivada, deja de ofrecerse a
 * nuevos apartados y sus comprobantes siguen apuntando a ella. Lo que esto
 * comunica es que **se completó de otra forma que la pedida**, y SP-3 exige
 * exactamente eso: «se convierte en desactivación, **informándolo**».
 *
 * La alternativa evidente era devolver un booleano —`true` si se borró, `false`
 * si se degradó— y es peor por un motivo que este repositorio ya se ha
 * encontrado dos veces: **un valor de retorno se puede ignorar sin escribir
 * nada**. Un llamante que no lo mire enseña «cuenta eliminada» sobre una
 * cuenta que sigue ahí, y ni el compilador ni PHPStan ni un test dicen nada.
 * Es el mismo modo de fallo que el `false` silencioso de `PublishedCatalogs` y
 * el de una ruta que no declara si sobrevive a la suspensión: lo que se olvida
 * pasa desapercibido. Aquí, olvidarlo no es posible.
 *
 * Por eso se lanza **fuera de la transacción que ya confirmó la
 * desactivación**: lanzar dentro la desharía, y entonces sí sería un fallo.
 *
 * No tiene código HTTP ni manejador en `bootstrap/app.php`, y es deliberado:
 * nunca llega al cliente como error. La recoge el controlador y se convierte
 * en el mensaje de la redirección, que es lo que ve el dueño del negocio.
 */
final class PaymentAccountInUseException extends RuntimeException
{
    private function __construct(
        public readonly PaymentAccount $account,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * La cuenta tenía comprobantes: se desactivó en vez de borrarse.
     */
    public static function deactivatedInstead(PaymentAccount $account): self
    {
        return new self($account, sprintf(
            'La cuenta %s tiene comprobantes de pago asociados, así que no se ha eliminado: queda desactivada '
            .'y deja de ofrecerse en los apartados nuevos. Sus comprobantes siguen mostrando a dónde se pagó.',
            self::describe($account),
        ));
    }

    /**
     * Cómo se nombra la cuenta en el mensaje.
     *
     * Con su método y su identificador, y no con su identificador a secas:
     * quien lee esto tiene varias cuentas y un número suelto no le dice cuál
     * de ellas acaba de desactivar.
     */
    private static function describe(PaymentAccount $account): string
    {
        return sprintf('de %s (%s)', $account->method->label(), $account->identifier);
    }
}
