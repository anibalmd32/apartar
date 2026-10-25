<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Receipt;
use RuntimeException;

final class ReceiptAlreadyProcessedException extends RuntimeException
{
    public const STATUS = 422;

    public function __construct(
        public readonly ReceiptStatus $status,
        public readonly ?string $reviewerName,
        public readonly ?RejectionReasonCode $reason = null,
    ) {
        $how = $status === ReceiptStatus::Approved ? 'aprobado' : 'rechazado';

        parent::__construct(match (true) {
            $reviewerName !== null => sprintf('Este comprobante ya fue %s por %s.', $how, $reviewerName),
            $reason === RejectionReasonCode::LayawayCancelled => 'Este comprobante ya fue rechazado: se rechazó solo al cancelar el apartado.',
            default => sprintf('Este comprobante ya fue %s.', $how),
        });
    }

    public static function of(Receipt $receipt): self
    {
        return new self($receipt->status, $receipt->reviewer?->name, $receipt->rejection_reason_code);
    }
}
