<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

/**
 * Qué pasó con la prórroga de la inicial al rechazarse un comprobante
 * (`REQ-LAY-006` RB-3, SP-2, SP-3).
 *
 * Son cuatro respuestas y no un booleano porque quien la pide —el rechazo del
 * comprobante, `T-062`— tiene que poder decirle al negocio **por qué** no
 * hubo prórroga: no es lo mismo «aún le quedaba ventana» que «ya usó la suya».
 */
enum DownPaymentExtension: string
{
    /** La ventana había vencido y se dieron 24 h desde el rechazo (RB-3). */
    case Granted = 'granted';

    /** La ventana seguía abierta al rechazar: no hace falta prórroga. */
    case WindowStillOpen = 'window_still_open';

    /** Ya tuvo su prórroga: `extended_at` estaba puesto (SP-3). */
    case AlreadyExtended = 'already_extended';

    /** El apartado ya no espera la inicial: activo, expirado o cancelado. */
    case NotAwaitingDownPayment = 'not_awaiting_down_payment';

    public function wasGranted(): bool
    {
        return $this === self::Granted;
    }
}
