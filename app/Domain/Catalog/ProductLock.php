<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * El bloqueo de la fila del producto, **el primero de toda transacción que
 * mueva stock** (`REQ-LAY-003` RB-3, RB-8).
 *
 * ## El orden es la regla
 *
 * Producto primero, y después cualquier otra fila del tenant: la ficha del
 * cliente, el apartado, el contador del plan. Dos transacciones que tomen dos
 * de esos bloqueos en orden distinto se abrazan, y ese fallo **no aparece en
 * la suite** —un *deadlock* solo existe entre transacciones confirmadas—: sale
 * en producción, bajo carga, como un `1213` sin causa aparente. Por eso hay un
 * solo sitio que lo toma, y `StockLockOrderTest` afirma sobre el registro de
 * consultas que es la primera fila bloqueada.
 *
 * Pedirlo dos veces en la misma transacción es gratis: InnoDB ya tiene el
 * bloqueo y lo concede. Así que quien reserva o libera **lo pide aunque su
 * llamante ya lo tenga**, y que sea la transacción la que decida; fiarse de
 * que el de arriba lo cogió es la forma en que el orden se rompe sin ruido.
 *
 * ## Y no alcanza a otro negocio
 *
 * La consulta pasa por el global scope (RB-7): el producto de otra
 * organización no se lee, no se bloquea y responde como si no existiera. No
 * hay un `DB::table()` aquí, y es donde más apetecería.
 */
final class ProductLock
{
    /**
     * @throws ModelNotFoundException si no es un producto de la organización activa
     * @throws LogicException fuera de una transacción: un `FOR UPDATE` en *autocommit* no bloquea nada
     */
    public static function acquire(int $productId): Product
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'El producto se bloquea dentro de la transacción que mueve su stock (REQ-LAY-003 RB-3). '
                .'Un FOR UPDATE fuera de una transacción se suelta en el acto y no serializa nada.'
            );
        }

        return Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
    }
}
