<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * La pantalla de revisión y la aprobación por HTTP (`T-061`, `REQ-PAY-004`
 * RB-6, `REQ-PAY-005`).
 *
 * Lo que mueve el dinero lo prueban `ApproveReceiptActionTest` y
 * `PaymentApplierTest`; aquí, que la pantalla enseña lo que hace falta, que la
 * vista previa dice lo que pasará y que cada negativa vuelve a su campo. Lo
 * de otra organización está en `Tests\Feature\Tenancy\ReceiptReviewIsolationTest`.
 */
final class ReceiptReviewPageTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-6: el archivo, lo declarado, el cronograma, el saldo, los pagos
     * previos con su tasa y lo que aplicaría FIFO con lo declarado —Bs. 2.550 a
     * 42,50 son $60: cuotas 1 y 2—.
     */
    public function test_the_screen_shows_what_is_needed_to_decide(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway, attributes: ['declared_amount' => '2550.00', 'declared_currency' => Currency::VES, 'bank' => 'Mercantil', 'reference' => '00042']);

        $this->actingAs($this->member)->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/receipts/Review')
            ->where('auth.user.id', $this->member->getKey())
            ->where('receipt.declared', 'Bs. 2.550,00')
            ->where('receipt.declaredAmount', '2550.00')
            ->where('receipt.bank', 'Mercantil')
            ->where('receipt.file.kind', 'image')
            ->where('layaway.code', $layaway->code)
            ->where('layaway.paid.usd', '$50.00')
            ->where('layaway.remaining.usd', '$150.00')
            ->has('installments', 5)
            ->has('payments', 1)
            ->where('payments.0.amountUsd.usd', '$50.00')
            ->where('payments.0.amountUsd.ves', null)
            ->where('rate.rate', '42.50000000')
            ->where('preview.summary', 'Se aplicará a las cuotas 1 y 2.')
            ->where('preview.amount', '$60.00')
            ->where('previewError', null)
            ->where('approval.url', route('receipts.approve', $receipt))
            ->where('approval.previewUrl', route('receipts.preview', $receipt)));
    }

    /**
     * La vista previa con otros datos: lo que diga el formulario, la tasa
     * escrita a mano o el motivo por el que no se puede.
     */
    public function test_the_preview_answers_for_what_is_written(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->actingAs($this->member);

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '30.00', 'currency' => 'USD']))
            ->assertOk()->assertJsonPath('preview.summary', 'Se aplicará a la cuota 1.');

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '1200.00', 'currency' => 'VES', 'rate' => '40']))
            ->assertOk()->assertJsonPath('preview.amount', '$30.00')->assertJsonPath('preview.rateSource', 'manual');

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '160.00', 'currency' => 'USD']))
            ->assertOk()->assertJsonPath('preview.unallocated', '$10.00');

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => 'cien', 'currency' => 'USD']))->assertStatus(422)->assertJsonStructure(['message']);
        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '30.00']))->assertStatus(422);

        $this->assertSame(1, DB::table('payments')->count(), 'Una vista previa dejó un pago escrito.');
    }

    /**
     * El *happy path*: aprobar desde la pantalla vuelve a la bandeja con lo
     * acreditado, y el comprobante sale de la cola.
     */
    public function test_approving_returns_to_the_inbox_with_what_was_credited(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());

        $this->actingAs($this->member)
            ->post(route('receipts.approve', $receipt), ['amount' => '2550.00', 'currency' => 'VES'])
            ->assertRedirect(route('receipts.index'))
            ->assertSessionHas('status', 'Comprobante aprobado: acreditamos $60.00 ($60.00 a las cuotas 1 y 2).');

        $this->assertSame('approved', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
        $this->get(route('receipts.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('teamMember.pendingReceipts', 0));
    }

    /**
     * SP-5 por HTTP: sin confirmar el sobrante vuelve al campo de la
     * confirmación sin escribir nada; confirmándolo, se aprueba.
     */
    public function test_an_excess_returns_to_its_confirmation_until_confirmed(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->actingAs($this->member)->from(route('receipts.show', $receipt));

        $this->post(route('receipts.approve', $receipt), ['amount' => '160.00', 'currency' => 'USD'])
            ->assertRedirect(route('receipts.show', $receipt))
            ->assertSessionHasErrors('confirm_excess');
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));

        $this->post(route('receipts.approve', $receipt), ['amount' => '160.00', 'currency' => 'USD', 'confirm_excess' => '500'])
            ->assertSessionHasErrors('confirm_excess');

        $this->post(route('receipts.approve', $receipt), ['amount' => '160.00', 'currency' => 'USD', 'confirm_excess' => '1000'])
            ->assertRedirect(route('receipts.index'))
            ->assertSessionHas('status', 'Comprobante aprobado: acreditamos $160.00 ($150.00 a las cuotas 1, 2, 3, 4 y 5; $10.00 sin asignar). El apartado está pagado.');
    }

    /**
     * Cada negativa en su campo: sin tasa en bolívares (SP-6), uno ya
     * aprobado con el nombre de quien lo aprobó (SP-1), un importe o una tasa
     * mal escritos.
     */
    public function test_each_refusal_comes_back_in_its_field(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $this->actingAs($this->member)->from(route('receipts.show', $receipt));

        $this->post(route('receipts.approve', $receipt), ['amount' => '30,00', 'currency' => 'USD'])->assertSessionHasErrors('amount');
        $this->post(route('receipts.approve', $receipt), ['amount' => '1200.00', 'currency' => 'VES', 'rate' => '40.123456789'])->assertSessionHasErrors('rate');
        $this->post(route('receipts.approve', $receipt), ['amount' => '30.00', 'currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->post(route('receipts.approve', $receipt), ['amount' => '0.00', 'currency' => 'USD'])->assertSessionHasErrors('amount');
        $this->post(route('receipts.approve', $receipt), ['amount' => '1200.00', 'currency' => 'VES', 'rate' => '0'])->assertSessionHasErrors('rate');

        DB::table('exchange_rates')->delete();
        Cache::flush();
        $this->post(route('receipts.approve', $receipt), ['amount' => '1200.00', 'currency' => 'VES'])->assertSessionHasErrors('rate');

        $this->post(route('receipts.approve', $receipt), ['amount' => '30.00', 'currency' => 'USD'])->assertRedirect(route('receipts.index'));
        $this->post(route('receipts.approve', $receipt), ['amount' => '30.00', 'currency' => 'USD'])
            ->assertSessionHasErrors(['amount' => 'Este comprobante ya fue aprobado por Ana Pérez.']);
    }

    /**
     * Por debajo de la inicial (`T-058`) vuelve al importe, y la confirmación
     * de un pago que paga la inicial dice qué parte fue a ella.
     */
    public function test_the_down_payment_is_refused_short_and_named_in_the_confirmation(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization), attributes: ['declared_amount' => '80.00', 'declared_currency' => Currency::USD]);
        $this->actingAs($this->member)->from(route('receipts.show', $receipt));

        $this->post(route('receipts.approve', $receipt), ['amount' => '40.00', 'currency' => 'USD'])->assertSessionHasErrors('amount');

        $this->post(route('receipts.approve', $receipt), ['amount' => '80.00', 'currency' => 'USD'])
            ->assertSessionHas('status', 'Comprobante aprobado: acreditamos $80.00 (inicial de $50.00; $30.00 a la cuota 1).');
    }

    /**
     * La vista previa acepta lo mismo que la aprobación: un importe sin cifra
     * entera, una tasa con más de ocho decimales o un parámetro repetido son
     * `422`, nunca un `500`.
     */
    public function test_the_preview_accepts_what_the_approval_accepts(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->actingAs($this->member);

        $this->getJson(route('receipts.preview', $receipt).'?amount=.99&currency=USD')->assertStatus(422);
        $this->getJson(route('receipts.preview', $receipt).'?amount[]=1&currency=USD')->assertStatus(422);
        $this->getJson(route('receipts.preview', $receipt).'?amount=30&currency[]=USD')->assertStatus(422);
        $this->getJson(route('receipts.preview', $receipt).'?amount=1200&currency=VES&rate=40.123456789')->assertStatus(422);
        $this->getJson(route('receipts.preview', $receipt).'?amount=30&currency=USD&rate=45')
            ->assertOk()->assertJsonPath('preview.rateSource', 'bcv');
    }

    /**
     * Los pagos previos son **de ese apartado**: los de otro cliente del mismo
     * negocio no aparecen.
     */
    public function test_the_previous_payments_are_those_of_the_layaway_only(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->activeLayaway();

        $this->actingAs($this->member)->get(route('receipts.show', $receipt))->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 1));
    }

    /**
     * Uno ya resuelto se ve con quién lo resolvió y sin formulario; su vista
     * previa responde que ya fue procesado.
     */
    public function test_a_resolved_receipt_is_shown_without_the_form(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway(), ReceiptStatus::Rejected, ['reviewed_by' => $this->member->getKey(), 'rejection_reason' => 'La referencia no aparece en el banco.']);

        $this->actingAs($this->member)->get(route('receipts.show', $receipt))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('approval', null)
            ->where('preview', null)
            ->where('receipt.reviewedBy', 'Ana Pérez')
            ->where('receipt.rejectionReason', 'La referencia no aparece en el banco.'));

        $this->getJson(route('receipts.preview', [$receipt, 'amount' => '30.00', 'currency' => 'USD']))
            ->assertStatus(422)->assertJsonPath('message', 'Este comprobante ya fue rechazado por Ana Pérez.');
    }

    /**
     * El de un apartado que espera la inicial dice que activará, y el de uno
     * que ya no cobra no ofrece aprobar.
     */
    public function test_the_screen_knows_what_the_layaway_can_take(): void
    {
        $waiting = $this->receiptFor($this->layawayIn($this->organization), attributes: ['declared_amount' => '50.00', 'declared_currency' => Currency::USD]);
        $completed = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Completed));
        $this->actingAs($this->member);

        $this->get(route('receipts.show', $waiting))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('installments', 0)
            ->where('preview.summary', 'Activará el apartado con la inicial de $50.00.'));

        $this->get(route('receipts.show', $completed))->assertInertia(fn (AssertableInertia $page) => $page->where('approval', null));
    }

    /**
     * `REQ-PAY-006` por HTTP: el rechazo vuelve a la bandeja; el motivo corto,
     * el código automático o uno ya resuelto vuelven a su campo. La pantalla
     * ofrece rechazar mientras espere revisión y enseña la cadena del
     * reintento.
     */
    public function test_rejecting_from_the_review_screen(): void
    {
        $layaway = $this->activeLayaway();
        $receipt = $this->receiptFor($layaway);
        $this->actingAs($this->member)->from(route('receipts.show', $receipt));

        $this->get(route('receipts.show', $receipt))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rejection.url', route('receipts.reject', $receipt))
            ->has('rejection.codes', 6));

        $this->post(route('receipts.reject', $receipt), ['code' => 'not_legible', 'reason' => 'Corta'])->assertSessionHasErrors('reason');
        $this->post(route('receipts.reject', $receipt), ['code' => 'layaway_cancelled', 'reason' => 'La imagen está cortada.'])->assertSessionHasErrors('code');

        $this->post(route('receipts.reject', $receipt), ['code' => 'not_legible', 'reason' => 'La imagen está cortada.'])
            ->assertRedirect(route('receipts.index'))
            ->assertSessionHas('status', 'Comprobante rechazado: el cliente verá tu explicación y podrá reportar de nuevo.');

        $this->post(route('receipts.reject', $receipt), ['code' => 'not_legible', 'reason' => 'La imagen está cortada.'])
            ->assertSessionHasErrors(['reason' => 'Este comprobante ya fue rechazado por Ana Pérez.']);

        $retry = $this->receiptFor($layaway, attributes: ['replaces_receipt_id' => $receipt->getKey()]);
        $this->get(route('receipts.show', $retry))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipt.replaces.url', route('receipts.show', $receipt))
            ->where('receipt.replaces.rejectionReason', 'La imagen está cortada.'));
        // Y hacia delante: el rechazado enlaza el reintento que lo sustituyó.
        $this->get(route('receipts.show', $receipt))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rejection', null)
            ->where('receipt.replacedBy.url', route('receipts.show', $retry)));
        $this->get(route('receipts.show', $retry))->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.replacedBy', null));
    }

    /**
     * `REQ-LAY-006` RB-3 en el aviso: quien rechaza sabe qué pasó con el plazo
     * de la inicial —dentro de plazo, prorrogado 24 horas, o ya prorrogado y a
     * punto de vencer—.
     */
    public function test_the_rejection_says_what_happened_to_the_down_payment_window(): void
    {
        $open = $this->layawayIn($this->organization);
        $late = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $open->getKey())->update(['expires_at' => '2026-09-11 10:00:00']);
        DB::table('layaways')->where('id', $late->getKey())->update(['expires_at' => '2026-09-10 09:00:00']);
        [$inTime, $first, $second] = [$this->receiptFor($open), $this->receiptFor($late), $this->receiptFor($late)];
        $this->actingAs($this->member);
        $reject = fn (Receipt $receipt) => $this->post(route('receipts.reject', $receipt), ['code' => 'not_legible', 'reason' => 'La imagen está cortada.']);
        $prefix = 'Comprobante rechazado: el cliente verá tu explicación y podrá reportar de nuevo.';

        $reject($inTime)->assertSessionHas('status', $prefix.' La inicial todavía está dentro de su plazo.');
        $reject($first)->assertSessionHas('status', $prefix.' Como el plazo de la inicial ya había vencido, el apartado tiene 24 horas más para pagarla.');
        $this->withoutOrganization();
        $reject($second)->assertSessionHas('status', $prefix.' El plazo de la inicial ya se prorrogó una vez: si no llega, el apartado vencerá.');
    }

    /**
     * RB-12: con la organización suspendida se revisa y se aprueba.
     */
    public function test_it_works_with_the_organization_suspended(): void
    {
        $receipt = $this->receiptFor($this->activeLayaway());
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAs($this->member);

        $this->get(route('receipts.show', $receipt))->assertOk();
        $this->post(route('receipts.approve', $receipt), ['amount' => '30.00', 'currency' => 'USD'])->assertRedirect(route('receipts.index'));
    }

    /**
     * Activado con su inicial, aprobando el comprobante de la inicial.
     */
    private function activeLayaway(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $initial = $this->receiptFor($layaway, attributes: ['declared_amount' => '50.00', 'declared_currency' => Currency::USD]);

        $this->actingAs($this->member)
            ->post(route('receipts.approve', $initial), ['amount' => '50.00', 'currency' => 'USD'])
            ->assertRedirect(route('receipts.index'));

        return $layaway;
    }

    /**
     * Un comprobante con su organización, sin dejarla fijada.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function receiptFor(Layaway $layaway, ReceiptStatus $status = ReceiptStatus::PendingReview, array $attributes = []): Receipt
    {
        $receipt = $this->withinOrganization($this->organization, fn (): Receipt => Receipt::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'status' => $status,
            ...$attributes,
        ]));
        $this->withoutOrganization();

        return $receipt;
    }
}
