<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Organization;
use App\Models\Receipt;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReceiptIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    public function test_the_neighbours_receipts_do_not_exist_for_our_questions(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        $this->receiptFor($neighbours);
        $this->receiptFor($neighbours, ReceiptStatus::Rejected);

        $this->withinOrganization($ours, function () use ($mine, $neighbours): void {
            $receipts = new LayawayReceipts;

            $this->assertSame(0, Receipt::query()->count());
            $this->assertFalse($receipts->holdExpiration($neighbours));
            $this->assertSame([], $receipts->pendingReviewCounts([$mine, $neighbours]));
            $this->assertSame([], $receipts->rejectedAwaitingRetry([$mine, $neighbours]));
            $this->assertSame(0, $receipts->rejectPendingOnCancellation($neighbours));
        });

        $this->assertSame(1, $this->withinOrganization($theirs, fn (): int => Receipt::query()->where('status', ReceiptStatus::PendingReview)->count()));
    }

    public function test_the_same_reference_and_file_are_accepted_in_two_organizations(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $hash = hash('sha256', 'la misma captura');

        $this->receiptFor($this->layawayIn($ours), attributes: ['bank' => 'Banesco', 'reference' => '123456', 'file_hash' => $hash]);
        $this->receiptFor($this->layawayIn($theirs), attributes: ['bank' => 'Banesco', 'reference' => '123456', 'file_hash' => $hash]);

        $this->assertSame(1, $this->withinOrganization($ours, fn (): int => Receipt::query()->where('reference', '123456')->count()));
        $this->assertSame(1, $this->withinOrganization($theirs, fn (): int => Receipt::query()->where('reference', '123456')->count()));
    }
}
