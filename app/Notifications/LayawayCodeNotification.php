<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El código de un apartado, reenviado por el negocio a un cliente que lo
 * perdió (`REQ-IDN-008` SP-5, `REQ-BOF-041` RB-5, `T-065`).
 *
 * Solo el código y adónde llevarlo: el correo de la confirmación habla del
 * plazo de la inicial y de las cuentas de cobro del momento de apartar, que
 * semanas después ya no dicen la verdad.
 */
final class LayawayCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $businessName,
        public readonly string $productName,
        public readonly string $portalUrl,
    ) {
        $this->onQueue('high');
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
        return (new MailMessage)
            ->subject(sprintf('Tu código de apartado en %s', $this->businessName))
            ->greeting('Aquí tienes tu código')
            ->line(sprintf('%s te reenvía el código de tu apartado de %s: **%s**.', $this->businessName, $this->productName, $this->code))
            ->line('Con él y tu documento puedes consultar tu apartado, tus cuotas y tus pagos.')
            ->action('Consultar mi apartado', $this->portalUrl)
            ->line(sprintf('El compromiso de pago es con %s. Apartar es la plataforma que usa para gestionar tu apartado.', $this->businessName));
    }
}
