<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
