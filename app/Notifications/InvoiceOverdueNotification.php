<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Subscription\InvoiceNoticeType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

final class InvoiceOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly InvoiceNoticeType $type,
        public readonly string $amount,
        public readonly string $dueDate,
        public readonly ?string $graceLastDay,
        public readonly string $invoicesUrl,
    ) {
        if ($type !== InvoiceNoticeType::Issued && $graceLastDay === null) {
            throw new InvalidArgumentException('Un aviso de impago dice hasta cuándo dura la gracia.');
        }

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
        $mail = (new MailMessage)
            ->subject($this->type->subject())
            ->greeting('Hola');

        $mail = match ($this->type) {
            InvoiceNoticeType::Issued => $mail
                ->line(sprintf('Emitimos la factura de tu suscripción por %s, con vencimiento el %s.', $this->amount, $this->dueDate))
                ->line('Puedes pagarla con pago móvil o transferencia y subir el comprobante desde tu historial de facturas.'),
            InvoiceNoticeType::Overdue => $mail
                ->line(sprintf('Tu factura de %s venció el %s y sigue sin pagar.', $this->amount, $this->dueDate))
                ->line(sprintf('Tu negocio sigue funcionando con normalidad hasta el %s. Si para entonces no se ha pagado, la suscripción se suspende.', $this->graceLastDay)),
            InvoiceNoticeType::GraceEndsInThreeDays, InvoiceNoticeType::GraceEndsInOneDay => $mail
                ->line(sprintf('Tienes hasta el %s para pagar %s.', $this->graceLastDay, $this->amount))
                ->line('Si se suspende, tus catálogos dejan de mostrarse y tu equipo solo podrá cobrar y aprobar comprobantes.'),
        };

        return $mail
            ->line('Tus clientes no se ven afectados: siguen pagando sus apartados y subiendo comprobantes.')
            ->action('Ver mis facturas', $this->invoicesUrl);
    }
}
