<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Tenancy\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TeamInvitation $invitation,
        private readonly string $organizationName,
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
            ->subject(sprintf('Te invitaron al equipo de %s en Apartar', $this->organizationName))
            ->greeting('Hola')
            ->line(sprintf(
                'Te sumaron al equipo de %s en Apartar. Para entrar solo tienes que elegir tu contraseña.',
                $this->organizationName,
            ))
            ->action('Elegir mi contraseña', $this->invitation->url())
            ->line(sprintf(
                'El enlace vale durante %d horas y una sola vez. Si se te pasa, pídele a quien '
                .'te invitó que te lo reenvíe.',
                TeamInvitation::TTL_HOURS,
            ))
            ->line('Si no esperabas esta invitación, ignora este correo: sin elegir una contraseña, nadie puede entrar con tu dirección.');
    }
}
