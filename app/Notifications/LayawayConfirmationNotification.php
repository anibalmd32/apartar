<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Payment\PaymentAccountView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo de confirmación del apartado (`REQ-LAY-001` RB-10, SP-9).
 *
 * Lleva lo mismo que la página de gracias: el código, lo que se apartó, cuánto
 * es la inicial, hasta cuándo, y **dónde pagarle al negocio**. Es el segundo
 * canal; el primero es la pantalla, y sin correo es el único (RB-10).
 *
 * ## Solo datos ya resueltos, nada del tenant
 *
 * Va a la cola —un fallo al enviar no revierte el apartado y el trabajo se
 * reintenta (SP-9)—, y por eso no lleva ningún modelo: el trabajo corre sin
 * organización fijada, y rehidratar un apartado o una cuenta de cobro fuera de
 * su tenant es exactamente lo que `REQ-ORG-002` RB-5 impide. Recibe el texto
 * ya formateado, como la página.
 *
 * Quien decide mandarlo es `LayawayController`, y **solo** si el apartado se
 * acaba de crear: un segundo envío con la misma clave devuelve el mismo
 * apartado, y escribirle dos veces a quien pulsó dos veces es ruido.
 */
final class LayawayConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<PaymentAccountView>  $paymentAccounts  las activas del negocio, en su orden
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
