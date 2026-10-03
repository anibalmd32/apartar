<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;
use App\Models\Receipt;

/**
 * Si una cuenta de cobro tiene comprobantes apuntándole (`REQ-ORG-007` RB-6,
 * SP-3).
 *
 * Existe por una regla concreta: **una cuenta con comprobantes no se borra, se
 * desactiva**. El motivo es el mismo que el de la baja lógica del equipo —
 * conservar la trazabilidad— y aquí es todavía más literal: un comprobante es
 * la prueba de **a dónde se pagó**, y borrar la cuenta lo deja apuntando al
 * vacío en un producto cuya función es conciliar dinero. El día que haya una
 * discusión con un cliente sobre un pago, «esa cuenta ya no existe» no es una
 * respuesta.
 *
 * La regla se escribió en `T-015` con su forma definitiva y sin tabla:
 * `receipts` llegó con `T-054`, que sustituyó el cuerpo por la consulta del
 * modelo **en la misma tarea**, sin tocar la acción, la ruta, el mensaje ni el
 * test. `UpsertPaymentAccountAction` pregunta, degrada el borrado y tiene su
 * test contra esta clase sustituida en el contenedor; por eso no es `final`.
 * La migración la respalda además con `restrict` en
 * `receipts.payment_account_id`.
 *
 * ## Por qué, hasta `T-054`, fallaba ruidosamente
 *
 * Esta es la lección que `T-014` dejó pagada. `PublishedCatalogs::exist()` nació
 * respondiendo `false` **comprobando que su tabla no existiera**, y eso lo
 * convertía en una bomba de relojería silenciosa: `T-024` crea `catalogs` ocho
 * tareas antes de que `T-032` rellene el cuerpo, y en esa ventana la regla
 * quedaba desactivada sin un solo test en rojo. El síntoma no habría aparecido
 * hasta que un negocio real perdiera su enlace.
 *
 * Aquí la guarda iba **al revés**: si `receipts` existía y este cuerpo seguía
 * sin sustituirse, **lanzaba**. Es la convención de fallo cerrado que ya rige las
 * declaraciones de superficie y de suspensión de las rutas y el `match` sin
 * `default` de `LimitEnforcer::measure()`: lo que no se ha decidido se para,
 * no se deja pasar. Un despliegue que reviente en el primer test es barato; una
 * regla de trazabilidad de dinero apagada en silencio no lo es.
 *
 * **Quien lo rellena es `T-054`**, la tarea que crea la tabla — no una
 * posterior. Ese es justamente el error que esta guarda impide repetir.
 */
class RecordedReceipts
{
    /**
     * Indica si esa cuenta tiene algún comprobante asociado, por el global
     * scope del tenant.
     */
    public function existFor(PaymentAccount $account): bool
    {
        return Receipt::query()->where('payment_account_id', $account->getKey())->exists();
    }
}
