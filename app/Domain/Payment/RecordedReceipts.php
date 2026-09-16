<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Schema;
use LogicException;

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
 * **La regla está escrita con su forma definitiva; lo que falta es la tabla.**
 * `receipts` llega con `T-054`, así que hoy la respuesta es que ninguna cuenta
 * tiene comprobantes — lo cual es literalmente cierto—.
 * `UpsertPaymentAccountAction` ya pregunta, ya degrada el borrado y ya tiene su
 * test, contra esta clase sustituida en el contenedor. Por eso no es `final`.
 *
 * ## Y por eso falla ruidosamente cuando llegue la tabla
 *
 * Esta es la lección que `T-014` dejó pagada. `PublishedCatalogs::exist()` nació
 * respondiendo `false` **comprobando que su tabla no existiera**, y eso lo
 * convertía en una bomba de relojería silenciosa: `T-024` crea `catalogs` ocho
 * tareas antes de que `T-032` rellene el cuerpo, y en esa ventana la regla
 * quedaba desactivada sin un solo test en rojo. El síntoma no habría aparecido
 * hasta que un negocio real perdiera su enlace.
 *
 * Aquí la guarda va **al revés**: si `receipts` existe y este cuerpo sigue sin
 * sustituirse, **lanza**. Es la convención de fallo cerrado que ya rige las
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
     * Indica si esa cuenta tiene algún comprobante asociado.
     *
     * @throws LogicException si `receipts` ya existe y nadie ha sustituido este cuerpo
     */
    public function existFor(PaymentAccount $account): bool
    {
        if (Schema::hasTable('receipts')) {
            throw new LogicException(sprintf(
                'La tabla `receipts` ya existe y `%s::existFor()` sigue respondiendo que la cuenta %d no tiene '
                .'comprobantes. `T-054` crea esa tabla y es quien tiene que sustituir este cuerpo por '
                .'`Receipt::query()->where(\'payment_account_id\', $account->getKey())->exists()`, que va por el '
                .'global scope del tenant. Hasta que lo haga, `REQ-ORG-007` RB-6 estaría desactivado: borrar una '
                .'cuenta con comprobantes los dejaría apuntando al vacío y ningún test se pondría en rojo.',
                self::class,
                (int) $account->getKey(),
            ));
        }

        // `T-054` sustituye esto por la consulta del modelo. Mientras la tabla
        // no exista, ninguna cuenta tiene comprobantes y esta es la única
        // respuesta honesta.
        return false;
    }
}
