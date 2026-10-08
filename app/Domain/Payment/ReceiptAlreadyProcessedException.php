<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Receipt;
use RuntimeException;

/**
 * El comprobante ya no espera a nadie (`REQ-PAY-005` SP-1, SP-3;
 * `REQ-PAY-004` SP-2).
 *
 * Dos personas del equipo abren el mismo comprobante y aprueban a la vez: el
 * bloqueo serializa, y la segunda se encuentra uno que ya no está en revisión.
 * Lo que necesita saber es **quién** lo resolvió y **cómo**, para no volver a
 * mirarlo ni a conciliarlo por otra vía. Sin nombre hay dos casos, y se
 * distinguen: lo rechazó el sistema al cancelar el apartado
 * (`REQ-LAY-009` SP-3), o quien lo resolvió ya no está en el equipo —el
 * revisor se anula si su fila se borra—.
 *
 * Es `422` y no `409`: la petición estaba bien formada y llega tarde, como
 * las demás negativas de negocio de este módulo.
 */
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

    /**
     * La de un comprobante ya resuelto, con lo que se sabe de cómo.
     */
    public static function of(Receipt $receipt): self
    {
        return new self($receipt->status, $receipt->reviewer?->name, $receipt->rejection_reason_code);
    }
}
