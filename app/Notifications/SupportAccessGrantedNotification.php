<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportAccessGrantedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $operatorName,
        private readonly string $reason,
        private readonly string $expiresAt,
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
            ->subject('El soporte de Apartar tiene acceso de lectura a tu negocio')
            ->greeting('Hola')
            ->line(sprintf('%s, del equipo de Apartar, abrió un acceso de solo lectura a los datos de tu negocio.', $this->operatorName))
            ->line('Motivo: '.$this->reason)
            ->line('Caduca solo: '.$this->expiresAt.'. No puede aprobar pagos, cambiar productos ni escribir a tus clientes.')
            ->line('Lo verás también en tu panel y en la auditoría mientras siga abierto.');
    }
}
