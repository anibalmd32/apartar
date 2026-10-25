<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
