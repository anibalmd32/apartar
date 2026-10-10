<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\SubmitSubscriptionReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Lo que el negocio declara al pagar su suscripción (`REQ-SUB-006` RB-2,
 * `T-069`): las comprobaciones de forma llegan antes que el archivo al disco.
 */
final class SubmitSubscriptionReceiptDataTest extends TestCase
{
    public function test_it_refuses_a_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->data(amount: '0.00');
    }

    public function test_a_method_that_needs_a_reference_needs_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->data(method: PaymentMethod::PagoMovil, reference: null);
    }

    public function test_an_empty_or_too_long_reference_is_refused(): void
    {
        foreach (['   ', str_repeat('9', SubmitSubscriptionReceiptData::TEXT_MAX + 1)] as $reference) {
            try {
                $this->data(reference: $reference);
                $this->fail('Una referencia vacía o demasiado larga no vale.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cash_needs_no_reference(): void
    {
        $this->assertNull($this->data(method: PaymentMethod::EfectivoUsd, reference: null, currency: Currency::USD)->reference);
    }

    private function data(PaymentMethod $method = PaymentMethod::PagoMovil, ?string $reference = '0123', string $amount = '637.50', Currency $currency = Currency::VES): SubmitSubscriptionReceiptData
    {
        return new SubmitSubscriptionReceiptData(
            invoice: new SubscriptionInvoice,
            method: $method,
            bank: 'Banesco',
            reference: $reference,
            paidAt: CarbonImmutable::now(),
            declaredAmount: $amount,
            declaredCurrency: $currency,
            file: UploadedFile::fake()->image('comprobante.jpg'),
        );
    }
}
