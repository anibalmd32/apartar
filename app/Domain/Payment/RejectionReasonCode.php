<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum RejectionReasonCode: string
{
    case NotLegible = 'not_legible';
    case AmountMismatch = 'amount_mismatch';
    case ReferenceNotFound = 'reference_not_found';
    case Duplicated = 'duplicated';
    case WrongAccount = 'wrong_account';
    case LayawayCancelled = 'layaway_cancelled';
    case Other = 'other';

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
     * @return list<self>
     */
    public static function chosenByStaff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $code): bool => $code !== self::LayawayCancelled));
    }
}
