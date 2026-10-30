<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Payment\PaymentAccountView;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Notifications\LayawayConfirmationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayConfirmationNotificationTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_it_carries_the_code_the_down_payment_its_deadline_and_where_to_pay(): void
    {
        $account = $this->withinOrganization(Organization::factory()->create(), fn (): PaymentAccount => PaymentAccount::factory()->create([
            'bank' => 'Banesco',
            'identifier' => '0414-7654321',
            'holder_name' => 'Ana Pérez',
            'holder_document' => 'V-12.345.678',
        ]));

        $mail = $this->notification([PaymentAccountView::of($account)])->toMail(new AnonymousNotifiable);
        $text = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

        $this->assertSame('Tu apartado AP-7K3MQ2XH en Ferretería Oriente', $mail->subject);
        $this->assertStringContainsString('AP-7K3MQ2XH', $text);
        $this->assertStringContainsString('2 × Nevera Mabe', $text);
        $this->assertStringContainsString('paga la inicial de $200,00 antes del 30 de septiembre', $text);
        $this->assertStringContainsString('Pago móvil (Banesco)', $text);
        $this->assertStringContainsString('0414-7654321', $text);
        $this->assertStringContainsString('El compromiso de pago es con Ferretería Oriente', $text);
    }

    public function test_without_down_payment_it_says_it_is_active_and_when_the_first_installment_is_due(): void
    {
        $notification = new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: '$150,00',
            downPayment: '$0,00',
            expiresAt: '',
            paymentAccounts: [],
            requiresDownPayment: false,
            firstInstallment: '$50,00',
            firstDueDate: '12 de octubre',
        );

        $text = implode("\n", $notification->toMail(new AnonymousNotifiable)->introLines);

        $this->assertStringContainsString('No lleva inicial: tu apartado ya está activo y lo pagas solo en cuotas. La primera, de $50,00, vence el 12 de octubre.', $text);
        $this->assertStringNotContainsString('paga la inicial', $text);
    }

    public function test_without_expiry_it_asks_for_the_down_payment_without_a_date(): void
    {
        $notification = new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: '$150,00',
            downPayment: '$50,00',
            expiresAt: '',
            paymentAccounts: [],
        );

        $text = implode('
', $notification->toMail(new AnonymousNotifiable)->introLines);

        $this->assertStringContainsString('Total: $150,00. Para confirmarlo, paga la inicial de $50,00.', $text);
        $this->assertStringNotContainsString('antes del', $text);
    }

    public function test_with_the_down_payment_already_paid_it_says_it_is_active(): void
    {
        $notification = new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: '$150,00',
            downPayment: '$50,00',
            expiresAt: '30 de septiembre a las 10:00 a. m.',
            paymentAccounts: [],
            firstInstallment: '$25,00',
            firstDueDate: '12 de octubre',
            downPaymentPaid: true,
        );

        $text = implode('
', $notification->toMail(new AnonymousNotifiable)->introLines);

        $this->assertStringContainsString('La inicial de $50,00 ya quedó pagada: tu apartado está activo. La primera cuota, de $25,00, vence el 12 de octubre.', $text);
        $this->assertStringNotContainsString('paga la inicial', $text);
    }

    public function test_with_the_first_installment_started_it_names_the_next_one_and_what_is_left(): void
    {
        $notification = new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: '$150,00',
            downPayment: '$50,00',
            expiresAt: '',
            paymentAccounts: [],
            firstInstallment: '$15,00',
            firstDueDate: '26 de octubre',
            downPaymentPaid: true,
            firstInstallmentNumber: 2,
            firstInstallmentStarted: true,
        );

        $text = implode("\n", $notification->toMail(new AnonymousNotifiable)->introLines);

        $this->assertStringContainsString('tu apartado está activo. Lo próximo es la cuota N.º 2: te quedan $15,00 por pagar y vence el 26 de octubre.', $text);
        $this->assertStringNotContainsString('La primera cuota', $text);
    }

    public function test_fully_paid_it_says_nothing_is_pending_and_lists_no_payment_accounts(): void
    {
        $account = $this->withinOrganization(Organization::factory()->create(), fn (): PaymentAccount => PaymentAccount::factory()->create());

        $notification = new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 1,
            total: '$150,00',
            downPayment: '$50,00',
            expiresAt: '',
            paymentAccounts: [PaymentAccountView::of($account)],
            downPaymentPaid: true,
            fullyPaid: true,
        );

        $text = implode("\n", $notification->toMail(new AnonymousNotifiable)->introLines);

        $this->assertStringContainsString('Total: $150,00. Ya quedó pagado por completo: no tienes nada pendiente.', $text);
        $this->assertStringNotContainsString('está activo', $text);
        $this->assertStringNotContainsString('Puedes pagarle', $text);
    }

    public function test_without_payment_accounts_it_sends_to_ask_the_business(): void
    {
        $mail = $this->notification([])->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('todavía no ha publicado sus formas de pago', implode("\n", $mail->introLines));
    }

    public function test_it_is_queued_and_carries_no_models(): void
    {
        $notification = $this->notification([]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);

        foreach ((new \ReflectionClass($notification))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string) $parameter->getType();
            $this->assertStringNotContainsString('App\\Models', $type, "{$parameter->getName()} es un modelo.");
        }
    }

    /**
     * @param  list<PaymentAccountView>  $accounts
     */
    private function notification(array $accounts): LayawayConfirmationNotification
    {
        return new LayawayConfirmationNotification(
            code: 'AP-7K3MQ2XH',
            businessName: 'Ferretería Oriente',
            productName: 'Nevera Mabe',
            quantity: 2,
            total: '$300,00',
            downPayment: '$200,00',
            expiresAt: '30 de septiembre a las 10:00 a. m.',
            paymentAccounts: $accounts,
        );
    }
}
