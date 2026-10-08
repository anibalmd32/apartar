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

/**
 * Lo que se difunde cuando entra un comprobante (`REQ-PAY-004` RB-1, RB-2).
 *
 * Lo que viaja por Reverb pasa por cada navegador suscrito, así que la lista
 * de campos es **cerrada**: el caso de las claves falla si alguien añade una,
 * y el de los datos personales busca, en el mensaje serializado, cada dato del
 * cliente y del pago que no debe salir.
 */
final class ReceiptSubmittedTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Receipt $receipt;

    private Layaway $layaway;

    /**
     * RB-2 · el payload, exacto: las claves y lo que vale cada una, sacado
     * del comprobante y de su apartado.
     */
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

    /**
     * RB-2 · y ninguno de los datos del cliente ni del pago aparece en lo que
     * se difunde: ni nombre, ni documento, ni teléfono, ni correo, ni banco,
     * ni referencia, ni nota, ni la cuenta de cobro.
     */
    public function test_the_payload_carries_no_personal_data(): void
    {
        $event = $this->eventFor();
        // Sin escapar: con el \u00ed de `json_encode` por defecto, «María» no
        // aparecería nunca en el texto aunque viajara.
        $wire = (string) json_encode($event->broadcastWith(), JSON_UNESCAPED_UNICODE);

        foreach (['María González', 'V98765432', '+584241112233', 'maria@example.com', 'Mercantil', '7788990011', 'Pagué la cuota 3', 'Titular Ejemplo', '0414-5556677', 'customer'] as $personal) {
            $this->assertStringNotContainsString($personal, $wire, sprintf('El payload difundido lleva «%s» (REQ-PAY-004 RB-2).', $personal));
        }
    }

    /**
     * RB-1 · va por el canal privado de **su** organización, el que
     * `routes/channels.php` registra.
     */
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
