<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * Lo que los comprobantes de un apartado deciden fuera de la cobranza, con
 * comprobantes de verdad (`T-054`).
 *
 * Hasta `T-054` las cuatro preguntas respondían «ninguno» y **lanzaban** en
 * cuanto existiera la tabla sin cuerpo; los tests de la expiración, las
 * alertas y la cancelación las sustituyen en el contenedor y siguen haciéndolo.
 * Aquí se prueban las consultas.
 */
final class LayawayReceiptsTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    private LayawayReceipts $receipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->receipts = new LayawayReceipts;
    }

    /**
     * `REQ-LAY-006` RB-1 y RB-2: en revisión o aprobado retiene la expiración;
     * rechazado, no; sin comprobantes, tampoco.
     */
    public function test_a_pending_or_approved_receipt_holds_the_expiration(): void
    {
        $none = $this->layawayIn($this->organization);
        $rejected = $this->layawayIn($this->organization);
        $pending = $this->layawayIn($this->organization);
        $approved = $this->layawayIn($this->organization);
        $this->receiptFor($rejected, ReceiptStatus::Rejected);
        $this->receiptFor($pending);
        $this->receiptFor($approved, ReceiptStatus::Approved);

        $held = $this->within(fn (): array => array_map(fn (Layaway $layaway): bool => $this->receipts->holdExpiration($layaway), [$none, $rejected, $pending, $approved]));

        $this->assertSame([false, false, true, true], $held);
    }

    /**
     * `REQ-LAY-023`: cuántos esperan revisión, por apartado, en una consulta.
     */
    public function test_it_counts_the_pending_receipts_per_layaway(): void
    {
        $two = $this->layawayIn($this->organization);
        $one = $this->layawayIn($this->organization);
        $none = $this->layawayIn($this->organization);
        $this->receiptFor($two);
        $this->receiptFor($two);
        $this->receiptFor($two, ReceiptStatus::Rejected);
        $this->receiptFor($one);
        $this->receiptFor($none, ReceiptStatus::Approved);

        $counts = $this->within(fn (): array => $this->receipts->pendingReviewCounts([$two, $one, $none]));

        $this->assertSame([(int) $two->getKey() => 2, (int) $one->getKey() => 1], $counts);
        $this->assertSame([], $this->within(fn (): array => $this->receipts->pendingReviewCounts([])));
    }

    /**
     * `REQ-LAY-023`: el rechazo es una alerta mientras sea lo último; un
     * reintento lo contesta.
     */
    public function test_a_rejection_awaits_a_retry_only_while_it_is_the_latest(): void
    {
        $waiting = $this->layawayIn($this->organization);
        $retried = $this->layawayIn($this->organization);
        $this->receiptFor($waiting, ReceiptStatus::Approved);
        $this->receiptFor($waiting, ReceiptStatus::Rejected);
        $first = $this->receiptFor($retried, ReceiptStatus::Rejected);
        $this->receiptFor($retried, attributes: ['replaces_receipt_id' => $first->getKey()]);

        $this->assertSame([(int) $waiting->getKey()], $this->within(fn (): array => $this->receipts->rejectedAwaitingRetry([$waiting, $retried])));
    }

    /**
     * `REQ-LAY-009` SP-3: al cancelar, los que esperan revisión se rechazan
     * con `layaway_cancelled` y sin revisor; los demás no se tocan.
     */
    public function test_cancelling_rejects_the_pending_receipts_only(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $pending = [$this->receiptFor($layaway), $this->receiptFor($layaway)];
        $approved = $this->receiptFor($layaway, ReceiptStatus::Approved);

        $this->assertSame(2, $this->within(fn (): int => $this->receipts->rejectPendingOnCancellation($layaway)));

        $this->within(function () use ($pending, $approved): void {
            foreach ($pending as $receipt) {
                $fresh = Receipt::query()->findOrFail($receipt->getKey());
                $this->assertSame(ReceiptStatus::Rejected, $fresh->status);
                $this->assertSame(RejectionReasonCode::LayawayCancelled, $fresh->rejection_reason_code);
                $this->assertNull($fresh->reviewed_by);
                $this->assertNotNull($fresh->reviewed_at);
            }

            $this->assertSame(ReceiptStatus::Approved, Receipt::query()->findOrFail($approved->getKey())->status);
        });
    }

    /**
     * `bank` y `reference` van juntos: el único de la pareja no ve un `NULL`,
     * así que la base rechaza uno sin el otro.
     */
    public function test_a_reference_without_a_bank_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->receiptFor($this->layawayIn($this->organization), attributes: ['bank' => null]);
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
