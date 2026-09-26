<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

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
}
