<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Por qué se rechazó un comprobante (`REQ-PAY-006`).
 *
 * El código clasifica; el texto que escribe el negocio explica, y el cliente
 * ve los dos (`REQ-LAY-022` RB-3). `layaway_cancelled` no lo elige nadie: lo
 * pone el sistema al cancelar un apartado con comprobantes en revisión
 * (`REQ-LAY-009` SP-3).
 */
enum RejectionReasonCode: string
{
    case NotLegible = 'not_legible';
    case AmountMismatch = 'amount_mismatch';
    case ReferenceNotFound = 'reference_not_found';
    case Duplicated = 'duplicated';
    case WrongAccount = 'wrong_account';
    case LayawayCancelled = 'layaway_cancelled';
    case Other = 'other';

    /**
     * Cómo se le dice al cliente y al negocio.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotLegible => 'No se lee el comprobante',
            self::AmountMismatch => 'El monto no coincide',
            self::ReferenceNotFound => 'La referencia no aparece en el banco',
            self::Duplicated => 'Comprobante repetido',
            self::WrongAccount => 'Se pagó a otra cuenta',
            self::LayawayCancelled => 'El apartado fue cancelado',
            self::Other => 'Otro motivo',
        };
    }

    /**
     * Los que puede elegir quien rechaza (`REQ-PAY-006` RB-1): todos menos el
     * del rechazo automático al cancelar el apartado (SP-6), que solo pone el
     * sistema. Una lista del tipo, no del formulario.
     *
     * @return list<self>
     */
    public static function chosenByStaff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $code): bool => $code !== self::LayawayCancelled));
    }
}
