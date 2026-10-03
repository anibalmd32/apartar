<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Domain\Money\Money;

/**
 * El estado de una cuota del cronograma (`REQ-LAY-004`, `REQ-LAY-007`).
 *
 * Se persiste en `installments.status` como `VARCHAR` ([02 §5]); toda cuota
 * nace `pending` —es el valor por defecto de la columna (`T-039`)—. Quién la
 * mueve y cuándo es de `T-046` (materializar), `T-048` (mora) y `T-058`
 * (aplicar pagos); aquí solo están los cuatro casos.
 *
 * Un pago que no cubre la cuota la deja `partially_paid`, y si además está
 * vencida pasada la gracia, `overdue`: es el estado de la **cuota**. El del
 * apartado no tiene intermedio (`REQ-LAY-005` SP-4).
 */
enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    /**
     * El estado que corresponde a una cuota por lo que vale, lo que lleva
     * pagado y si ya pasó su gracia (`REQ-LAY-007` RB-1, RB-4).
     *
     * Es la única regla, y la comparten la detección de mora (`T-048`) y la
     * aplicación de pagos (`T-058`): si cada una derivara el estado a su
     * manera, una cuota podría quedar `partially_paid` por un camino y
     * `overdue` por el otro con los mismos números.
     *
     *  - cubierta (`paid >= amount`) → `paid`, pase lo que pase con la fecha;
     *  - sin cubrir y pasada la gracia → `overdue`, **también a medio pagar**
     *    (RB-4, SP-2): pagar $10 de $30 no saca a nadie de la mora;
     *  - sin cubrir y dentro de la gracia → `partially_paid` si hay algo
     *    pagado, `pending` si no.
     *
     * `$pastGrace` lo calcula quien llama, con el «hoy» y la gracia de la
     * organización: aquí no hay reloj ni zona.
     */
    public static function resolve(Money $amount, Money $paid, bool $pastGrace): self
    {
        if ($paid->cents >= $amount->cents) {
            return self::Paid;
        }

        if ($pastGrace) {
            return self::Overdue;
        }

        return $paid->cents > 0 ? self::PartiallyPaid : self::Pending;
    }
}
