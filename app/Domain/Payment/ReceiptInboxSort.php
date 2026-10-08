<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Support\SortWhitelist;

/**
 * Por qué se puede ordenar la bandeja de comprobantes (`REQ-PAY-004` RB-4,
 * `REQ-BOF-045` RB-4).
 *
 * RB-4 fija el orden **por defecto** —la cola FIFO: los pendientes primero y,
 * entre ellos, el más antiguo arriba—, y ese no es un caso de aquí: es lo que
 * la bandeja hace cuando no se pide ninguno. Lo que se puede pedir son las
 * dos fechas de la fila: cuándo llegó (para ver lo último que entró, que en la
 * cola FIFO va al final) y qué día dice el cliente que pagó (para cuadrar con
 * el extracto del banco).
 *
 * No hay orden por importe: los comprobantes vienen en dólares y en bolívares
 * sin convertir, y ordenar «850» de una moneda junto a «850» de la otra sería
 * un orden que miente.
 */
enum ReceiptInboxSort: string
{
    case Submitted = 'submitted';

    case PaidAt = 'paid_at';

    /**
     * La columna que ordena este caso, cualificada: la consulta lleva un
     * `EXISTS` sobre los apartados.
     */
    public function column(): string
    {
        return match ($this) {
            self::Submitted => 'receipts.created_at',
            self::PaidAt => 'receipts.paid_at',
        };
    }

    /**
     * @return SortWhitelist<self>
     */
    public static function whitelist(): SortWhitelist
    {
        return new SortWhitelist(self::cases());
    }
}
