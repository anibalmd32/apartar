<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubscriptionSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $invoicesUrl)
    {
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
        return (new MailMessage)
            ->subject('Tu suscripción de Apartar está suspendida')
            ->greeting('Hola')
            ->line('Se acabó el plazo para pagar tu factura y suspendimos la suscripción de tu negocio.')
            ->line('Tus catálogos dejaron de mostrarse. Tu equipo puede seguir cobrando y aprobando comprobantes, y tus clientes siguen pagando sus apartados con normalidad.')
            ->line('En cuanto aprobemos tu pago se levanta la suspensión. Los catálogos no se vuelven a publicar solos: eliges tú cuáles.')
            ->action('Pagar mi factura', $this->invoicesUrl);
    }
}
