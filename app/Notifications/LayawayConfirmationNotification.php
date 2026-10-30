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
        public readonly bool $requiresDownPayment = true,
        public readonly ?string $firstInstallment = null,
        public readonly ?string $firstDueDate = null,
        public readonly bool $downPaymentPaid = false,
        public readonly int $firstInstallmentNumber = 1,
        public readonly bool $firstInstallmentStarted = false,
        public readonly bool $fullyPaid = false,
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
            ->line($this->nextStep());

        if ($this->fullyPaid) {
            return $message->line(sprintf(
                'El compromiso de pago es con %s. Apartar es la plataforma que usa para gestionar tu apartado.',
                $this->businessName,
            ));
        }

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

    private function nextStep(): string
    {
        if ($this->fullyPaid) {
            return sprintf('Total: %s. Ya quedó pagado por completo: no tienes nada pendiente.', $this->total);
        }

        if ($this->requiresDownPayment && $this->downPaymentPaid) {
            $paid = sprintf('Total: %s. La inicial de %s ya quedó pagada: tu apartado está activo.', $this->total, $this->downPayment);

            return $this->firstInstallment === null || $this->firstDueDate === null
                ? $paid
                : $paid.' '.$this->upcomingInstallment('La primera cuota');
        }

        if ($this->requiresDownPayment && $this->expiresAt === '') {
            return sprintf('Total: %s. Para confirmarlo, paga la inicial de %s.', $this->total, $this->downPayment);
        }

        if ($this->requiresDownPayment) {
            return sprintf(
                'Total: %s. Para confirmarlo, paga la inicial de %s antes del %s.',
                $this->total,
                $this->downPayment,
                $this->expiresAt,
            );
        }

        if ($this->firstInstallment === null || $this->firstDueDate === null) {
            return sprintf('Total: %s. No lleva inicial: tu apartado ya está activo y lo pagas solo en cuotas.', $this->total);
        }

        return sprintf(
            'Total: %s. No lleva inicial: tu apartado ya está activo y lo pagas solo en cuotas. %s',
            $this->total,
            $this->upcomingInstallment('La primera'),
        );
    }

    private function upcomingInstallment(string $first): string
    {
        if ($this->firstInstallmentNumber === 1 && ! $this->firstInstallmentStarted) {
            return sprintf('%s, de %s, vence el %s.', $first, $this->firstInstallment, $this->firstDueDate);
        }

        return sprintf(
            'Lo próximo es la cuota N.º %d: te quedan %s por pagar y vence el %s.',
            $this->firstInstallmentNumber,
            $this->firstInstallment,
            $this->firstDueDate,
        );
    }
}
