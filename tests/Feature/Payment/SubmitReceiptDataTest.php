<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * Lo que el cliente declara al subir un comprobante (`REQ-PAY-001`): lo que
 * la tabla rechazaría o la regla prohíbe no se puede construir.
 */
final class SubmitReceiptDataTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);
    }

    /**
     * El *happy path*: pago móvil con banco y referencia, a una cuenta del
     * negocio, Bs. 850,00 sin convertir.
     */
    public function test_a_bank_payment_with_bank_and_reference_to_an_own_account(): void
    {
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());

        $data = $this->data(account: $account);

        $this->assertSame(85_000, $data->declared()->cents);
        $this->assertSame(Currency::VES, $data->declared()->currency);
        $this->assertSame($account, $data->paymentAccount);
    }

    /**
     * RB-2: un pago por banco sin banco o sin referencia no existe.
     */
    public function test_a_bank_payment_needs_bank_and_reference(): void
    {
        foreach ([[null, '123456'], ['Banesco', null], ['  ', '123456'], ['Banesco', str_repeat('9', 101)]] as [$bank, $reference]) {
            try {
                $this->data(bank: $bank, reference: $reference);
                $this->fail('Se construyó un pago móvil sin banco o sin referencia válidos.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('REQ-PAY-001 RB-2', $exception->getMessage());
            }
        }
    }

    /**
     * `REQ-PAY-003` RB-3: el efectivo no lleva ninguno de los dos, y sin ellos
     * se construye.
     */
    public function test_cash_carries_neither_bank_nor_reference(): void
    {
        $cash = $this->data(method: PaymentMethod::EfectivoUsd, bank: null, reference: null, amount: '30.00', currency: Currency::USD);
        $this->assertSame(3_000, $cash->declared()->cents);

        foreach ([['Banesco', null], [null, '123456'], ['Banesco', '123456']] as [$bank, $reference]) {
            try {
                $this->data(method: PaymentMethod::EfectivoVes, bank: $bank, reference: $reference);
                $this->fail('Se construyó un pago en efectivo con banco o referencia.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('REQ-PAY-003 RB-3', $exception->getMessage());
            }
        }
    }

    /**
     * RB-5: positivo y de dos decimales.
     */
    public function test_the_declared_amount_is_positive_with_two_decimals(): void
    {
        foreach (['0', '-850.00', '850.001', '1.234,50'] as $amount) {
            try {
                $this->data(amount: $amount);
                $this->fail("Se construyó con el importe {$amount}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * SP-7 y `REQ-ORG-002` RB-7: una cuenta de cobro de otro negocio en el
     * payload es un dato imposible.
     */
    public function test_a_payment_account_of_another_organization_is_refused(): void
    {
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): PaymentAccount => PaymentAccount::factory()->create());

        $this->expectException(CrossTenantRelationException::class);

        $this->data(account: $foreign);
    }

    public function test_the_note_is_at_most_500_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->data(note: str_repeat('a', 501));
    }

    private function data(
        ?PaymentAccount $account = null,
        PaymentMethod $method = PaymentMethod::PagoMovil,
        ?string $bank = 'Banesco',
        ?string $reference = '0123456789',
        string $amount = '850.00',
        Currency $currency = Currency::VES,
        ?string $note = null,
    ): SubmitReceiptData {
        return new SubmitReceiptData(
            $this->layaway,
            $account,
            $method,
            $bank,
            $reference,
            CarbonImmutable::parse('2026-10-01'),
            $amount,
            $currency,
            $note,
            UploadedFile::fake()->create('captura.jpg', 120, 'image/jpeg'),
        );
    }
}
