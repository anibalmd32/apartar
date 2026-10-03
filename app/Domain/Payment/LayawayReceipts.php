<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Layaway;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Lo que los comprobantes de un apartado deciden fuera del módulo de cobranza.
 *
 * ## Si congelan su expiración (`REQ-LAY-006` RB-1, RB-2)
 *
 * Un apartado en `pending_down_payment` con la ventana vencida no expira si
 * tiene un comprobante en revisión o aprobado: el cliente pagó a tiempo, y la
 * demora es del negocio. Castigarle por ella —devolverle el producto a la
 * venta con su dinero ya enviado— es exactamente lo que RB-2 prohíbe.
 *
 * ## Que se rechazan al cancelar (`REQ-LAY-009` SP-3)
 *
 * Un apartado cancelado no acepta pagos, así que sus comprobantes en revisión
 * se rechazan en la misma transacción, con el código `layaway_cancelled`.
 *
 * ## Qué alertas dan en el portal (`REQ-LAY-023`)
 *
 * Cuántos esperan revisión —`receipt_pending`, y lo que sustituye al aviso de
 * la ventana de la inicial cuando ya hay uno en camino (SP-3)— y qué apartados
 * tienen su último comprobante rechazado sin reemplazo —`receipt_rejected`—.
 *
 * **Las reglas están escritas con su forma definitiva; lo que falta es la
 * tabla.** `receipts` llega con `T-054`, así que hoy la respuesta es que ningún
 * apartado tiene comprobantes, que es literalmente cierto. `ExpireLayawaysAction`,
 * `PortalAlertsQuery` y `CancelLayawayAction` ya preguntan, y sus tests
 * sustituyen esta clase en el contenedor para probar la retención, las alertas
 * y el rechazo. Por eso no es `final`.
 *
 * ## Y falla ruidosamente cuando llegue la tabla
 *
 * Es la guarda de `RecordedReceipts` (`T-015`), y por la misma razón: si
 * `receipts` existe y estos cuerpos siguen sin sustituirse, **lanzan**.
 * Responder «ninguno» en silencio con la tabla ya creada expiraría apartados
 * con el comprobante del cliente esperando revisión, y escondería en el
 * portal el rechazo que el cliente necesita para corregir; ningún test se
 * pondría rojo.
 *
 * **Quien los rellena es `T-054`**, la tarea que crea la tabla.
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
        $this->assertTheTableIsStillMissing(__FUNCTION__, sprintf(
            'una consulta de `Receipt` con estado `pending_review` o `approved` del apartado %d, por el global scope del '
            .'tenant. Hasta que lo haga, `REQ-LAY-006` RB-2 estaría desactivado: el job expiraría apartados con el '
            .'comprobante del cliente esperando revisión.',
            (int) $layaway->getKey(),
        ));

        // `T-054` sustituye esto por la consulta del modelo. Mientras la tabla
        // no exista, ningún apartado tiene comprobantes.
        return false;
    }

    /**
     * Cuántos comprobantes en revisión tiene cada uno de esos apartados, por
     * `id`; los que no tienen ninguno no aparecen.
     *
     * Son los apartados de **un** negocio, y se consulta con su tenant fijado:
     * una consulta para todos, no una por apartado.
     *
     * @param  list<Layaway>  $layaways
     * @return array<int, int>
     *
     * @throws LogicException si `receipts` ya existe y nadie ha sustituido este cuerpo
     */
    public function pendingReviewCounts(array $layaways): array
    {
        $this->assertTheTableIsStillMissing(__FUNCTION__, 'un `COUNT` de `Receipt` en `pending_review` agrupado por '
            .'`layaway_id`, por el global scope del tenant. Hasta que lo haga, el portal no diría «comprobante en '
            .'revisión» (`REQ-LAY-023` SP-3) y seguiría apremiando con la ventana de la inicial a quien ya pagó.');

        return [];
    }

    /**
     * Los `id` de esos apartados cuyo **último** comprobante fue rechazado y
     * no tiene todavía uno que lo reemplace.
     *
     * Un rechazo ya contestado con un reintento deja de ser una alerta: lo que
     * queda es el reintento, en revisión.
     *
     * @param  list<Layaway>  $layaways
     * @return list<int>
     *
     * @throws LogicException si `receipts` ya existe y nadie ha sustituido este cuerpo
     */
    public function rejectedAwaitingRetry(array $layaways): array
    {
        $this->assertTheTableIsStillMissing(__FUNCTION__, 'los apartados cuyo último `Receipt` está `rejected` y ningún '
            .'otro lo reemplaza (`replaces_receipt_id`), por el global scope del tenant. Hasta que lo haga, el cliente '
            .'no se enteraría en el portal de que su comprobante fue rechazado (`REQ-LAY-023` RB-2).');

        return [];
    }

    /**
     * Rechaza los comprobantes en revisión del apartado que se está cancelando,
     * con el código `layaway_cancelled`, y dice cuántos (`REQ-LAY-009` SP-3).
     *
     * Corre dentro de la transacción de la cancelación, con el apartado ya
     * bloqueado: o se cancela y se rechazan, o ninguna de las dos cosas. Así no
     * quedan comprobantes huérfanos en la bandeja de un apartado que ya no
     * acepta pagos.
     *
     * @throws LogicException si `receipts` ya existe y nadie ha sustituido este cuerpo
     */
    public function rejectPendingOnCancellation(Layaway $layaway): int
    {
        $this->assertTheTableIsStillMissing(__FUNCTION__, sprintf(
            'el rechazo de cada `Receipt` en `pending_review` del apartado %d con `RejectionReasonCode::LayawayCancelled`, '
            .'por el global scope del tenant, y su aviso al cliente. Hasta que lo haga, cancelar dejaría comprobantes '
            .'huérfanos en la bandeja de conciliación (`REQ-LAY-009` SP-3).',
            (int) $layaway->getKey(),
        ));

        return 0;
    }

    /**
     * @throws LogicException
     */
    private function assertTheTableIsStillMissing(string $method, string $replacement): void
    {
        if (Schema::hasTable('receipts')) {
            throw new LogicException(sprintf(
                'La tabla `receipts` ya existe y `%s::%s()` sigue respondiendo que no hay comprobantes. `T-054` crea '
                .'esa tabla y es quien tiene que sustituir este cuerpo por %s',
                self::class,
                $method,
                $replacement,
            ));
        }
    }
}
