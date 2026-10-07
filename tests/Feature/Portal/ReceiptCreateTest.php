<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Http\Controllers\Portal\ReceiptController;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * El cliente reporta un pago desde el portal (`REQ-PAY-001`, `T-059`): el
 * formulario y su envío, de punta a punta por HTTP.
 *
 * El negocio está en `Asia/Tokyo`, trece horas por delante de la zona de la
 * aplicación: el apartado se crea el 1/10 a las 12:00 de Caracas, que son las
 * 16:00 UTC del 1/10 y ya la 1:00 del 2/10 en Tokio. «Hoy» y «el día de
 * creación» son días del calendario del negocio, y una fecha tomada de la
 * zona de la aplicación **o de UTC** cae en otro día y se nota (la revisión
 * de `T-059` cazó que a las 20:00 UTC y Tokio coincidían).
 */
final class ReceiptCreateTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        Queue::fake();
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente', 'timezone' => 'Asia/Tokyo']));
        $this->layaway = $this->layawayIn($this->organization);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->createOne(['holder_name' => 'Ana Pérez']));
        $this->withoutOrganization();
        $this->actingAs($this->customer, 'customer');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-3, RB-4, RB-9 y `REQ-PAY-002` RB-5: el formulario llega con todo
     * resuelto —cuentas activas, métodos, monedas, los bordes de la fecha en
     * el calendario del negocio y el importe sugerido, en crudo y formateado—.
     */
    public function test_the_form_arrives_with_everything_resolved(): void
    {
        $this->withinOrganization($this->organization, fn () => PaymentAccount::factory()->inactive()->create());

        $this->get($this->formUrl())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/ReceiptCreate')
            ->where('business.name', 'Ferretería Oriente')
            ->where('layaway.code', $this->layaway->code)
            ->where('layaway.remaining.cents', 20_000)
            ->where('suggestion.kind', 'down_payment')
            ->where('suggestion.amount.cents', 5_000)
            ->where('suggestion.amountUsd', '50.00')
            ->where('suggestion.amountVes', null)
            ->has('paymentAccounts', 1)
            ->where('paymentAccounts.0.id', $this->account->getKey())
            ->has('methods', 6)
            ->where('methods.0.value', 'pago_movil')
            ->where('methods.0.requiresReference', true)
            ->where('methods.0.defaultCurrency', 'VES')
            ->where('currencies.1.label', 'Bolívares (VES)')
            ->where('paidAt.min', '2026-10-02')
            ->where('paidAt.max', '2026-10-02')
            ->where('file.maxMb', 5)
            ->where('file.accept', '.jpg,.jpeg,.png,.webp,.pdf')
            ->where('storeUrl', route('portal.receipts.store', ['code' => $this->layaway->code]))
            ->where('backUrl', route('portal.layaways.show', ['code' => $this->layaway->code])));
    }

    /**
     * RB-9 · con tasa, la sugerencia trae también la cifra en bolívares a la
     * tasa de **este** negocio, sin formato, para precargar el campo.
     */
    public function test_with_a_rate_the_suggestion_also_comes_in_bolivars(): void
    {
        ExchangeRate::factory()->create(['rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);

        $this->get($this->formUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('suggestion.amountUsd', '50.00')
            ->where('suggestion.amountVes', '2125.00'));
    }

    /**
     * RB-1, SP-4: un apartado de otra persona no existe; uno que ya no cobra
     * no recibe comprobantes, ni el formulario ni el envío —este, antes de
     * validar nada—; y sin sesión se pasa por el acceso.
     */
    public function test_foreign_terminal_and_anonymous_are_turned_away(): void
    {
        $foreign = $this->layawayIn($this->organization);
        $this->withoutOrganization();
        $this->get(route('portal.receipts.create', ['code' => $foreign->code]))->assertNotFound();
        $this->post(route('portal.receipts.store', ['code' => $foreign->code]), [])->assertNotFound();

        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        DB::table('layaways')->where('id', $cancelled->getKey())->update(['customer_id' => $this->customer->getKey()]);
        $this->withoutOrganization();
        $this->get(route('portal.receipts.create', ['code' => $cancelled->code]))->assertForbidden();
        $this->post(route('portal.receipts.store', ['code' => $cancelled->code]), [])->assertForbidden();

        auth()->guard('customer')->logout();
        $this->get($this->formUrl())->assertRedirect(route('portal.access'));
    }

    /**
     * El *happy path*: el comprobante nace en revisión, el archivo queda bajo
     * la carpeta de su organización, el saldo no se mueve (RB-6) y se vuelve
     * al detalle con el aviso.
     */
    public function test_a_valid_receipt_is_created_in_review_without_touching_the_balance(): void
    {
        $this->post($this->storeUrl(), $this->payload())
            ->assertRedirect(route('portal.layaways.show', ['code' => $this->layaway->code]))
            ->assertSessionHas('status', ReceiptController::SUBMITTED_STATUS);

        $receipt = $this->withinOrganization($this->organization, fn (): Receipt => Receipt::query()->sole());
        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
        $this->assertSame($this->account->getKey(), $receipt->payment_account_id);
        $this->assertSame($this->customer->getKey(), $receipt->customer_id);
        $this->assertSame('850.00', $receipt->declared_amount);
        $this->assertSame('2026-10-02', $receipt->paid_at->toDateString());
        $this->assertStringStartsWith($this->organization->getKey().'/', $receipt->file_path);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);

        $row = DB::table('layaways')->where('id', $this->layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame([0, 20_000, 'pending_down_payment'], [(int) $row->paid_cents, (int) $row->remaining_cents, $row->status]);
    }

    /**
     * RB-4, SP-5 · la fecha de pago, en el calendario **del negocio**: hoy en
     * Tokio ya es el 2/10 y se acepta; el 3/10 es futuro; y, días después,
     * el día de creación entra y el anterior no.
     */
    public function test_the_payment_date_is_bounded_in_the_business_calendar(): void
    {
        $this->post($this->storeUrl(), $this->payload(['paidAt' => '2026-10-03']))->assertSessionHasErrors('paidAt');
        $this->post($this->storeUrl(), $this->payload(['paidAt' => '2026-10-02']))->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow('2026-10-05 12:00:00');
        $this->post($this->storeUrl(), $this->payload(['paidAt' => '2026-10-01']))->assertSessionHasErrors('paidAt');
        $this->post($this->storeUrl(), $this->payload(['paidAt' => '2026-10-02', 'reference' => '0000000002']))->assertSessionHasNoErrors();
    }

    /**
     * RB-2, RB-3, SP-7: la referencia según el método; la cuenta, activa y de
     * este negocio; en efectivo, banco y referencia se descartan.
     */
    public function test_reference_and_payment_account_rules(): void
    {
        $this->post($this->storeUrl(), $this->payload(['reference' => '']))->assertSessionHasErrors('reference');

        $neighbours = $this->withinOrganization($this->neighbourOrganization(), fn (): PaymentAccount => PaymentAccount::factory()->createOne());
        $this->post($this->storeUrl(), $this->payload(['paymentAccount' => $neighbours->getKey()]))->assertSessionHasErrors('paymentAccount');

        $inactive = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->inactive()->createOne());
        $this->post($this->storeUrl(), $this->payload(['paymentAccount' => $inactive->getKey()]))->assertSessionHasErrors('paymentAccount');

        $this->post($this->storeUrl(), $this->payload(['method' => 'efectivo_usd', 'currency' => 'USD', 'amount' => '50.00', 'paymentAccount' => null]))
            ->assertSessionHasNoErrors();
        $cash = $this->withinOrganization($this->organization, fn (): Receipt => Receipt::query()->sole());
        $this->assertNull($cash->bank);
        $this->assertNull($cash->reference);
        $this->assertNull($cash->payment_account_id);
    }

    /**
     * `REQ-PAY-002` RB-5, SP-1, SP-2: el tipo real por contenido y el tamaño.
     */
    public function test_the_file_must_really_be_a_receipt_and_not_too_heavy(): void
    {
        // Con los mensajes del formulario, no con los de la acción: si una
        // regla se cayera, la segunda barrera respondería en el mismo campo
        // con otro texto, y el test tiene que notarlo.
        $this->post($this->storeUrl(), $this->payload(['file' => $this->receiptUpload('captura.jpg', "MZ\x90\x00 esto no es una imagen")]))
            ->assertSessionHasErrors(['file' => 'El archivo no es una imagen JPG, PNG o WebP ni un PDF. Comprueba que no sea otra cosa con el nombre cambiado.']);

        $this->post($this->storeUrl(), $this->payload(['file' => UploadedFile::fake()->create('justificante.pdf', 6 * 1024, 'application/pdf')]))
            ->assertSessionHasErrors(['file' => 'El comprobante pesa más de 5 MB. Si es un PDF, sube mejor una captura.']);

        // Un importe que no cabe en la columna es un error de su campo, no un
        // `500` del `INSERT`.
        $this->post($this->storeUrl(), $this->payload(['amount' => '100000000000000']))->assertSessionHasErrors('amount');

        $this->post($this->storeUrl(), $this->payload(['file' => $this->receiptPdf()]))->assertSessionHasNoErrors();
    }

    /**
     * `REQ-PAY-003` SP-2, RB-6 · un duplicado en la organización vuelve a su
     * campo con el mensaje que no revela nada de otros; la misma referencia en
     * otro negocio se acepta (SP-3, RB-4).
     */
    public function test_duplicates_come_back_on_their_field(): void
    {
        $file = $this->receiptImage();
        $this->post($this->storeUrl(), $this->payload(['file' => $file]))->assertSessionHasNoErrors();

        // Junto a otro error de validación: si lo cazara la acción y no el
        // formulario, con el importe inválido nunca llegaría a ejecutarse.
        $this->post($this->storeUrl(), $this->payload(['file' => $this->sameFileAs($file), 'reference' => '9999999999', 'amount' => '0']))
            ->assertSessionHasErrors(['file' => 'Este comprobante ya fue reportado.', 'amount']);
        $this->post($this->storeUrl(), $this->payload(['amount' => '0']))
            ->assertSessionHasErrors(['reference' => 'Este comprobante ya fue reportado.', 'amount']);

        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => Receipt::query()->count()));
    }

    /**
     * RB-12 · diez envíos por hora y por ficha; el undécimo, `429`, valga o
     * no lo que lleve.
     */
    public function test_receipt_submissions_are_rate_limited_per_customer(): void
    {
        for ($i = 0; $i < AppServiceProvider::RECEIPT_SUBMISSIONS_PER_HOUR; $i++) {
            $this->post($this->storeUrl(), ['method' => 'nada'])->assertSessionHasErrors('method');
        }

        $this->post($this->storeUrl(), ['method' => 'nada'])->assertStatus(429);
    }

    /**
     * RB-10, RB-11 · con el negocio suspendido y la cuota de almacenamiento
     * agotada, el comprobante del cliente se acepta igual.
     */
    public function test_a_suspended_business_with_exhausted_storage_still_receives_receipts(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->withinOrganization($this->organization, fn () => DB::table('usage_counters')->updateOrInsert(
            ['organization_id' => $this->organization->getKey(), 'metric' => 'storage_mb'],
            ['value' => 1_000_000, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ));

        $this->post($this->storeUrl(), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'paymentAccount' => $this->account->getKey(),
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0000000001',
            'paidAt' => '2026-10-02',
            'amount' => '850.00',
            'currency' => 'VES',
            'note' => null,
            'file' => $this->receiptImage(),
            ...$overrides,
        ];
    }

    private function formUrl(): string
    {
        return route('portal.receipts.create', ['code' => $this->layaway->code]);
    }

    private function storeUrl(): string
    {
        return route('portal.receipts.store', ['code' => $this->layaway->code]);
    }
}
