<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Layaway\LayawayStatus;
use App\Events\ReceiptSubmitted;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

final class ReceiptSubmittedTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Receipt $receipt;

    private Layaway $layaway;

    public function test_the_payload_is_minimal(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
        $event = $this->eventFor();
        CarbonImmutable::setTestNow();

        $this->assertSame([
            'id' => $this->receipt->getKey(),
            'layawayCode' => $this->layaway->code,
            'declaredAmount' => '850.00',
            'declaredCurrency' => 'VES',
            'submittedAt' => '2026-10-07T12:00:00-04:00',
        ], $event->broadcastWith());
    }

    public function test_the_payload_carries_no_personal_data(): void
    {
        $event = $this->eventFor();
        $wire = (string) json_encode($event->broadcastWith(), JSON_UNESCAPED_UNICODE);

        foreach (['María González', 'V98765432', '+584241112233', 'maria@example.com', 'Mercantil', '7788990011', 'Pagué la cuota 3', 'Titular Ejemplo', '0414-5556677', 'customer'] as $personal) {
            $this->assertStringNotContainsString($personal, $wire, sprintf('El payload difundido lleva «%s».', $personal));
        }
    }

    public function test_it_is_broadcast_on_the_private_channel_of_its_organization(): void
    {
        $event = $this->eventFor();
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame(sprintf('private-organization.%d.receipts', $event->organizationId), $channels[0]->name);
    }

    private function eventFor(): ReceiptSubmitted
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($organization, LayawayStatus::Active);
        DB::table('customers')->where('id', $layaway->customer_id)->update([
            'full_name' => 'María González',
            'document_number' => 'V98765432',
            'phone' => '+584241112233',
            'email' => 'maria@example.com',
        ]);
        $account = $this->withinOrganization($organization, fn (): PaymentAccount => PaymentAccount::factory()->create([
            'holder_name' => 'Titular Ejemplo',
            'identifier' => '0414-5556677',
        ]));

        $receipt = $this->receiptFor($layaway, attributes: [
            'bank' => 'Mercantil',
            'reference' => '7788990011',
            'note' => 'Pagué la cuota 3',
            'payment_account_id' => $account->getKey(),
        ]);

        $this->receipt = $receipt;
        $this->layaway = $layaway;

        return ReceiptSubmitted::of($receipt, $layaway->code);
    }
}
