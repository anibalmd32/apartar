<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CustomerLoginLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $businessName,
        public readonly string $expiresAt,
    ) {
        $this->onQueue('high');
        $this->afterCommit();
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
            ->subject(sprintf('Tu enlace para entrar a tus apartados en %s', $this->businessName))
            ->greeting(sprintf('Tus apartados en %s', $this->businessName))
            ->line(sprintf('Pediste entrar a tu cuenta con %s. Pulsa el botón para entrar; el enlace sirve una sola vez.', $this->businessName))
            ->action('Entrar a mis apartados', $this->url)
            ->line(sprintf('Caduca hoy a las %s. Si no lo pediste tú, ignora este correo: nadie puede entrar sin abrirlo.', $this->expiresAt));
    }
}
