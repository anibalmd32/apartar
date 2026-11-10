<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ProductPhotosReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly bool $approved,
        public readonly string $productName,
        public readonly ?string $reason,
        public readonly string $productUrl,
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

        if ($this->approved) {
            return $mail
                ->subject(sprintf('Aprobamos las fotos de «%s»', $this->productName))
                ->line(sprintf('Revisamos las fotos de «%s» y ya puedes publicarlo en tus catálogos.', $this->productName))
                ->action('Ver el producto', $this->productUrl);
        }

        return $mail
            ->subject(sprintf('No aprobamos las fotos de «%s»', $this->productName))
            ->line(sprintf('Revisamos las fotos de «%s» y no muestran un producto que se pueda vender en Apartar.', $this->productName))
            ->line(sprintf('Motivo: %s', $this->reason ?? ''))
            ->line('El producto sigue en borrador. Si cambias las fotos, puedes pedir otra revisión desde su ficha.')
            ->action('Ver el producto', $this->productUrl);
    }
}
