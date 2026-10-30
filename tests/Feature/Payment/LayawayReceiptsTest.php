<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayReceiptsTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private Organization $organization;

    private LayawayReceipts $receipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->receipts = new LayawayReceipts;
    }

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

    public function test_a_payment_recorded_by_the_business_holds_the_expiration_like_an_approved_receipt(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->within(fn () => app(PaymentApplier::class)->apply(
            $layaway, new Money(1_000), null, '10.00', Currency::USD, null, User::factory()->create(), 'Abono en tienda.',
        ));

        $this->assertTrue($this->within(fn (): bool => $this->receipts->holdExpiration($layaway)));
    }

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

    public function test_the_receipt_a_retry_replaces(): void
    {
        $none = $this->layawayIn($this->organization);
        $pending = $this->layawayIn($this->organization);
        $approved = $this->layawayIn($this->organization);
        $waiting = $this->layawayIn($this->organization);
        $settled = $this->layawayIn($this->organization);
        $paidBefore = $this->layawayIn($this->organization);
        $this->receiptFor($pending, ReceiptStatus::Rejected);
        $this->receiptFor($pending);
        $this->receiptFor($approved, ReceiptStatus::Rejected);
        $this->receiptFor($approved, ReceiptStatus::Approved);
        $this->receiptFor($waiting, ReceiptStatus::Approved);
        $rejected = $this->receiptFor($waiting, ReceiptStatus::Rejected);
        $this->rejectedAt($settled, '2026-09-10 09:00:00');
        $this->paymentAt($settled, '2026-09-10 10:00:00');
        $this->paymentAt($paidBefore, '2026-09-10 08:00:00');
        $stillWaiting = $this->rejectedAt($paidBefore, '2026-09-10 09:00:00');

        $replaced = $this->within(fn (): array => array_map(
            fn (Layaway $layaway): ?int => $this->receipts->rejectedToReplace($layaway)?->getKey(),
            [$none, $pending, $approved, $waiting, $settled, $paidBefore],
        ));

        $this->assertSame([null, null, null, $rejected->getKey(), null, $stillWaiting->getKey()], $replaced);
        $this->assertSame(
            [(int) $waiting->getKey(), (int) $paidBefore->getKey()],
            $this->within(fn (): array => $this->receipts->rejectedAwaitingRetry([$waiting, $settled, $paidBefore])),
        );
    }

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

    public function test_a_reference_without_a_bank_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->receiptFor($this->layawayIn($this->organization), attributes: ['bank' => null]);
    }

    private function rejectedAt(Layaway $layaway, string $at): Receipt
    {
        return $this->receiptFor($layaway, ReceiptStatus::Rejected, ['reviewed_at' => $at, 'updated_at' => $at]);
    }

    private function paymentAt(Layaway $layaway, string $at): void
    {
        $this->within(fn (): Payment => Payment::factory()->createOne(['layaway_id' => $layaway->getKey(), 'created_at' => $at]));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
