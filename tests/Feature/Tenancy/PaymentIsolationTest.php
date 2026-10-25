<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentIsolationTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_payments_allocations_and_receipts_are_invisible_to_the_neighbour(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $this->withinOrganization($ours, function (): void {
            Receipt::factory()->createOne();
            PaymentAllocation::factory()->createOne();
        });

        $visible = $this->withinOrganization($theirs, fn (): array => [
            Receipt::query()->count(),
            Payment::query()->count(),
            PaymentAllocation::query()->count(),
        ]);

        $this->assertSame([0, 0, 0], $visible);
        $this->assertSame([1, 1, 1], $this->withinOrganization($ours, fn (): array => [
            Receipt::query()->count(),
            Payment::query()->count(),
            PaymentAllocation::query()->count(),
        ]));
    }

    public function test_the_factories_seed_each_row_in_the_organization_of_its_layaway(): void
    {
        $this->withoutOrganization();

        $allocation = PaymentAllocation::factory()->createOne();
        $receipt = Receipt::factory()->createOne();
        $organization = Organization::query()->findOrFail($allocation->organization_id);

        $this->withinOrganization($organization, function () use ($allocation): void {
            $payment = Payment::query()->findOrFail($allocation->payment_id);

            $this->assertSame((int) $allocation->organization_id, (int) $payment->organization_id);
            $this->assertSame((int) $payment->organization_id, (int) Layaway::query()->findOrFail($payment->layaway_id)->organization_id);
            $this->assertSame((int) $payment->layaway_id, (int) Installment::query()->findOrFail($allocation->installment_id)->layaway_id);
        });

        $this->assertSame(
            (int) $receipt->organization_id,
            (int) $this->withinOrganization(Organization::query()->findOrFail($receipt->organization_id), fn (): mixed => Layaway::query()->findOrFail($receipt->layaway_id)->organization_id),
        );
    }
}
