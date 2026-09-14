<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Tenancy\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo con el que alguien entra a un equipo (`REQ-ORG-004` RB-4).
 *
 * Es el único sitio donde el token en claro sale del proceso, y de ahí sus dos
 * particularidades.
 *
 * **Va en la cola `high`.** No por volumen —son unos pocos al mes— sino porque
 * es un correo que alguien está esperando **ahora**: el `owner` acaba de decir
 * «te acabo de invitar, míralo». Detrás de una exportación de Excel, el enlace
 * llegaría media hora después de esa frase.
 *
 * **Y lleva el nombre del negocio como dato, no como consulta.** La
 * notificación se serializa para la cola y se ejecuta después, en un proceso
 * donde no hay tenant fijado (`REQ-ORG-002` RB-5): pedirle la organización al
 * usuario notificado en ese momento consultaría una tabla del tenant sin
 * contexto y lanzaría. El nombre viaja en el payload, que es lo que la regla
 * pide de todo lo que cruza a una cola.
 */
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
            // El plazo se dice en el correo porque es lo que evita el caso de
            // SP-3: quien lo lee el jueves sabe que el enlace del martes ya no
            // vale y pide otro en vez de darlo por roto.
            ->line(sprintf(
                'El enlace vale durante %d horas y una sola vez. Si se te pasa, pídele a quien '
                .'te invitó que te lo reenvíe.',
                TeamInvitation::TTL_HOURS,
            ))
            ->line('Si no esperabas esta invitación, ignora este correo: sin elegir una contraseña, nadie puede entrar con tu dirección.');
    }
}
