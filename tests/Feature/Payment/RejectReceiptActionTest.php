<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\RejectReceiptAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El negocio rechaza un comprobante (`T-062`, `REQ-PAY-006`).
 *
 * El apartado que espera la inicial vence el 13/09 a las 10:00 —tres días
 * después de crearse—, para que la prórroga de RB-6 se pueda probar a los
 * dos lados del plazo.
 */
final class RejectReceiptActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private const REASON = 'La imagen está cortada y no se ve la referencia.';

    private Organization $organization;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        Storage::fake(Receipt::DISK);
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->reviewer = $this->within(fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-1, RB-2, RB-7: guarda el código, el texto, quién y cuándo; **el
     * archivo y la fila se quedan**; y queda auditado.
     */
    public function test_it_keeps_the_reason_the_file_and_the_row(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));
        Storage::disk(Receipt::DISK)->put($receipt->file_path, 'captura');

        $this->reject($receipt, RejectionReasonCode::NotLegible);

        $row = DB::table('receipts')->where('id', $receipt->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(['rejected', 'not_legible', self::REASON, $this->reviewer->getKey(), '2026-09-10 10:00:00'], [$row->status, $row->rejection_reason_code, $row->rejection_reason, $row->reviewed_by, $row->reviewed_at]);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReceiptRejected->value)->sole());
        $after = $entry->properties?->toArray()['changed'] ?? [];
        $this->assertSame(['not_legible', self::REASON], [$after['rejection_reason_code']['after'] ?? null, $after['rejection_reason']['after'] ?? null]);
        $this->assertSame($this->reviewer->getKey(), $entry->causer_id);
    }

    /**
     * RB-6: la prórroga de 24 h solo si el plazo de la inicial **ya venció**
     * al rechazar; dentro del plazo no hace falta.
     */
    public function test_the_extension_is_granted_only_once_the_window_closed(): void
    {
        $open = $this->layawayIn($this->organization);
        $closed = $this->layawayIn($this->organization);
        DB::table('layaways')->whereIn('id', [$open->getKey(), $closed->getKey()])->update(['expires_at' => '2026-09-13 10:00:00']);
        CarbonImmutable::setTestNow('2026-09-12 10:00:00');

        $this->assertSame(DownPaymentExtension::WindowStillOpen, $this->reject($this->receiptFor($open)));
        $this->assertSame('2026-09-13 10:00:00', DB::table('layaways')->where('id', $open->getKey())->value('expires_at'));

        CarbonImmutable::setTestNow('2026-09-14 08:00:00');

        $this->assertSame(DownPaymentExtension::Granted, $this->reject($this->receiptFor($closed)));
        $this->assertSame('2026-09-15 08:00:00', DB::table('layaways')->where('id', $closed->getKey())->value('expires_at'));
    }

    /**
     * SP-1, SP-5 y RB-8: uno ya resuelto no se vuelve a resolver —ni
     * rechazar un aprobado ni aprobar un rechazado— y el segundo recibe el
     * nombre de quien lo resolvió.
     */
    public function test_a_resolved_receipt_cannot_be_resolved_again(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $approved = $this->receiptFor($layaway, attributes: ['declared_amount' => '50.00', 'declared_currency' => Currency::USD]);
        $this->within(fn () => app(ApproveReceiptAction::class)->handle($approved, new ApproveReceiptData('50.00', Currency::USD, null, null), $this->reviewer));
        $rejected = $this->receiptFor($layaway);
        $this->reject($rejected);

        $first = $this->refused(fn () => $this->reject($approved));
        $second = $this->refused(fn () => $this->within(fn () => app(ApproveReceiptAction::class)->handle($rejected, new ApproveReceiptData('30.00', Currency::USD, null, null), $this->reviewer)));

        $this->assertInstanceOf(ReceiptAlreadyProcessedException::class, $first);
        $this->assertSame('Este comprobante ya fue aprobado por Ana Pérez.', $first->getMessage());
        $this->assertSame('Este comprobante ya fue rechazado por Ana Pérez.', $second->getMessage());
        $this->assertSame(1, DB::table('payments')->count());
    }

    /**
     * RB-1 y SP-6: el motivo corto no vale, y el código del rechazo
     * automático al cancelar no lo elige nadie. Nada se escribe.
     */
    public function test_the_reason_is_required_and_the_automatic_code_is_not_offered(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));

        $this->assertInstanceOf(InvalidArgumentException::class, $this->refused(fn () => $this->reject($receipt, reason: 'Ilegible')));
        $this->assertInstanceOf(InvalidArgumentException::class, $this->refused(fn () => $this->reject($receipt, RejectionReasonCode::LayawayCancelled)));
        $this->assertSame('pending_review', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    /**
     * Quien no es de esa organización no rechaza, aunque tenga el modelo; con
     * la organización suspendida se rechaza igual.
     */
    public function test_only_the_business_rejects_even_when_suspended(): void
    {
        $receipt = $this->receiptFor($this->layawayIn($this->organization, LayawayStatus::Active));
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->assertInstanceOf(AuthorizationException::class, $this->refused(fn () => $this->within(fn () => app(RejectReceiptAction::class)->handle($receipt, RejectionReasonCode::Other, self::REASON, $intruder))));

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->reject($receipt);

        $this->assertSame('rejected', DB::table('receipts')->where('id', $receipt->getKey())->value('status'));
    }

    // -------------------------------------------------------------------

    private function reject(Receipt $receipt, RejectionReasonCode $code = RejectionReasonCode::NotLegible, string $reason = self::REASON): DownPaymentExtension
    {
        return $this->within(fn (): DownPaymentExtension => app(RejectReceiptAction::class)->handle($receipt, $code, $reason, $this->reviewer));
    }

    /**
     * @param  Closure(): mixed  $attempt
     */
    private function refused(Closure $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('Tenía que haberse rechazado.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
