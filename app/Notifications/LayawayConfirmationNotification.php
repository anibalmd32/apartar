<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Payment\PaymentAccountView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LayawayConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<PaymentAccountView>  $paymentAccounts
     */
    public function __construct(
        public readonly string $code,
        public readonly string $businessName,
        public readonly string $productName,
        public readonly int $quantity,
        public readonly string $total,
        public readonly string $downPayment,
        public readonly string $expiresAt,
        public readonly array $paymentAccounts,
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
        $message = (new MailMessage)
            ->subject(sprintf('Tu apartado %s en %s', $this->code, $this->businessName))
            ->greeting('¡Listo, ya está apartado!')
            ->line(sprintf(
                '%s%s, en %s. Tu código de apartado es **%s**: guárdalo, es el que te pedirán para consultar tu apartado.',
                $this->quantity > 1 ? $this->quantity.' × ' : '',
                $this->productName,
                $this->businessName,
                $this->code,
            ))
            ->line(sprintf(
                'Total: %s. Para confirmarlo, paga la inicial de %s antes del %s.',
                $this->total,
                $this->downPayment,
                $this->expiresAt,
            ));

        if ($this->paymentAccounts === []) {
            return $message->line(sprintf('%s todavía no ha publicado sus formas de pago: consúltale cómo pagarle.', $this->businessName));
        }

        $message->line('Puedes pagarle por cualquiera de estas vías:');

        foreach ($this->paymentAccounts as $account) {
            $message->line(sprintf(
                '%s%s — %s: %s · %s, %s',
                $account->methodLabel,
                $account->bank !== null ? ' ('.$account->bank.')' : '',
                $account->identifierLabel,
                $account->identifier,
                $account->holderName,
                $account->holderDocument,
            ));
        }

        return $message->line(sprintf(
            'El compromiso de pago es con %s. Apartar es la plataforma que usa para gestionar tu apartado.',
            $this->businessName,
        ));
    }
}
