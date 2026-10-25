<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Payment\PaymentAccountView;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Notifications\LayawayConfirmationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

final class LayawayConfirmationNotificationTest extends TestCase
{
    use RefreshDatabase;

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
