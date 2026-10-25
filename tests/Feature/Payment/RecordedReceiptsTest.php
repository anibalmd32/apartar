<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RecordedReceipts;
use App\Models\Organization;
use App\Models\PaymentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class RecordedReceiptsTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_an_account_with_receipts_has_them_and_one_without_does_not(): void
    {
        [$used, $unused] = $this->withinOrganization($this->organization, fn (): array => [PaymentAccount::factory()->create(), PaymentAccount::factory()->create()]);
        $this->receiptFor($this->layawayIn($this->organization), ReceiptStatus::Rejected, ['payment_account_id' => $used->getKey()]);

        $this->withinOrganization($this->organization, function () use ($used, $unused): void {
            $this->assertTrue((new RecordedReceipts)->existFor($used));
            $this->assertFalse((new RecordedReceipts)->existFor($unused));
        });
    }

    public function test_an_account_with_a_manual_payment_has_one_too(): void
    {
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $layaway = $this->layawayIn($this->organization);
        $this->withinOrganization($this->organization, fn () => \App\Models\Payment::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'payment_account_id' => $account->getKey(),
        ]));

        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => (new RecordedReceipts)->existFor($account)));
    }

    public function test_the_database_refuses_to_delete_an_account_with_receipts(): void
    {
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->receiptFor($this->layawayIn($this->organization), attributes: ['payment_account_id' => $account->getKey()]);

        $this->expectException(QueryException::class);

        DB::table('payment_accounts')->where('id', $account->getKey())->delete();
    }

    public function test_it_can_be_replaced_to_exercise_the_degraded_delete(): void
    {
        $withReceipts = new class extends RecordedReceipts
        {
            public function existFor(PaymentAccount $account): bool
            {
                return true;
            }
        };

        $this->assertTrue($withReceipts->existFor($this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create())));
    }
}
