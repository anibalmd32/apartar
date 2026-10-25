<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubscriptionReceiptReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const APPROVED = 'approved';

    public const PARTIAL = 'partial';

    public const REJECTED = 'rejected';

    /**
     * @param  self::APPROVED|self::PARTIAL|self::REJECTED  $outcome
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $outstanding,
        public readonly ?string $reason,
        public readonly string $invoicesUrl,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hola');

        $mail = match ($this->outcome) {
            self::APPROVED => $mail
                ->subject('Aprobamos el pago de tu suscripción')
                ->line('Revisamos tu comprobante y la factura quedó pagada. Gracias.'),
            self::PARTIAL => $mail
                ->subject('Recibimos un pago parcial de tu suscripción')
                ->line(sprintf('Aprobamos tu comprobante, pero no cubre la factura completa: faltan %s.', $this->outstanding ?? '')),
            default => $mail
                ->subject('No pudimos aprobar el comprobante de tu suscripción')
                ->line(sprintf('Motivo: %s', $this->reason ?? ''))
                ->line('La factura sigue pendiente y puedes subir otro comprobante.'),
        };

        return $mail->action('Ver mis facturas', $this->invoicesUrl);
    }
}
