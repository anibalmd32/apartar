<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Layaway;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Si un apartado tiene un comprobante que **congela su expiración**
 * (`REQ-LAY-006` RB-1, RB-2).
 *
 * Un apartado en `pending_down_payment` con la ventana vencida no expira si
 * tiene un comprobante en revisión o aprobado: el cliente pagó a tiempo, y la
 * demora es del negocio. Castigarle por ella —devolverle el producto a la
 * venta con su dinero ya enviado— es exactamente lo que RB-2 prohíbe.
 *
 * **La regla está escrita con su forma definitiva; lo que falta es la tabla.**
 * `receipts` llega con `T-054`, así que hoy la respuesta es que ningún
 * apartado tiene comprobantes, que es literalmente cierto. `ExpireLayawaysAction`
 * ya pregunta, y sus tests sustituyen esta clase en el contenedor para probar
 * la retención. Por eso no es `final`.
 *
 * ## Y falla ruidosamente cuando llegue la tabla
 *
 * Es la guarda de `RecordedReceipts` (`T-015`), y por la misma razón: si
 * `receipts` existe y este cuerpo sigue sin sustituirse, **lanza**. Responder
 * `false` en silencio con la tabla ya creada expiraría apartados con el
 * comprobante del cliente esperando revisión, y ningún test se pondría rojo.
 *
 * **Quien lo rellena es `T-054`**, la tarea que crea la tabla.
 */
class LayawayReceipts
{
    /**
     * Indica si el apartado tiene algún comprobante en revisión o aprobado.
     *
     * @throws LogicException si `receipts` ya existe y nadie ha sustituido este cuerpo
     */
    public function holdExpiration(Layaway $layaway): bool
    {
        if (Schema::hasTable('receipts')) {
            throw new LogicException(sprintf(
                'La tabla `receipts` ya existe y `%s::holdExpiration()` sigue respondiendo que el apartado %d no tiene '
                .'comprobantes. `T-054` crea esa tabla y es quien tiene que sustituir este cuerpo por una consulta de '
                .'`Receipt` con estado `pending_review` o `approved` de ese apartado, por el global scope del tenant. '
                .'Hasta que lo haga, `REQ-LAY-006` RB-2 estaría desactivado: el job expiraría apartados con el '
                .'comprobante del cliente esperando revisión.',
                self::class,
                (int) $layaway->getKey(),
            ));
        }

        // `T-054` sustituye esto por la consulta del modelo. Mientras la tabla
        // no exista, ningún apartado tiene comprobantes.
        return false;
    }
}
