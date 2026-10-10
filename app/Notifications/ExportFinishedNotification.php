<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El aviso de que la exportación terminó, lista o fallida (`REQ-BOF-043`
 * RB-6, SP-2, `T-070`).
 *
 * **Por correo, con el enlace a la pantalla de exportaciones y no al
 * archivo**: el enlace firmado caduca en 24 h y el correo se lee cuando se
 * lee; la pantalla firma uno nuevo cada vez. El aviso dentro de la aplicación
 * es esa misma pantalla, que enseña el estado de cada una; el centro de
 * notificaciones es de H7.
 *
 * Lleva el tipo, el estado y la URL como datos: se ejecuta en la cola, sin
 * tenant fijado, y no consulta nada.
 */
final class ExportFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ExportType $type,
        private readonly ExportStatus $status,
        private readonly string $exportsUrl,
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
        $ready = $this->status === ExportStatus::Completed;

        return (new MailMessage)
            ->subject($ready
                ? sprintf('Tu exportación de %s está lista', mb_strtolower($this->type->label()))
                : sprintf('No pudimos generar tu exportación de %s', mb_strtolower($this->type->label())))
            ->greeting('Hola')
            ->line($ready
                ? 'El archivo de Excel que pediste ya se puede descargar. Estará disponible siete días.'
                : 'Algo falló al generar el archivo. Puedes volver a pedirlo desde la misma pantalla.')
            ->action($ready ? 'Ir a descargarla' : 'Ver exportaciones', $this->exportsUrl);
    }

    public function status(): ExportStatus
    {
        return $this->status;
    }
}
