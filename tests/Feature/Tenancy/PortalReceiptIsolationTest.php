<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * El formulario del comprobante contra la organización vecina
 * (`REQ-QAX-008` RB-2, `REQ-PAY-001` RB-1, SP-7; `T-059`).
 *
 * La misma persona con ficha en dos negocios, entrando por el código de uno:
 * el apartado del otro no existe para su sesión —ni el formulario ni el
 * envío—, y una cuenta de cobro del otro negocio en el payload es un error de
 * su campo que no dice que exista. Es el `404` que sostiene la excepción
 * `{code}` de `CrossTenantAccessTest` para estas dos rutas.
 */
final class PortalReceiptIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase, UploadsReceiptFiles;

    public function test_the_neighbours_layaway_and_payment_account_are_out_of_reach(): void
    {
        Storage::fake(Receipt::DISK);
        Queue::fake();

        $ours = $this->subscribe(Organization::factory()->create());
        $theirs = $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        $theirAccount = $this->withinOrganization($theirs, fn (): PaymentAccount => PaymentAccount::factory()->createOne());
        DB::table('customers')->update(['document_number' => 'V12345678']);

        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($mine->customer_id));
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.receipts.create', ['code' => $neighbours->code]))->assertNotFound();
        $this->post(route('portal.receipts.store', ['code' => $neighbours->code]), $this->payload(null))->assertNotFound();

        $this->post(route('portal.receipts.store', ['code' => $mine->code]), $this->payload($theirAccount->getKey()))
            ->assertSessionHasErrors(['paymentAccount' => 'Esa cuenta de cobro no está disponible. Elige otra de la lista.']);

        $this->assertSame(0, DB::table('receipts')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $paymentAccount): array
    {
        return [
            'paymentAccount' => $paymentAccount,
            'method' => 'pago_movil',
            'bank' => 'Banesco',
            'reference' => '0000000001',
            'paidAt' => now()->toDateString(),
            'amount' => '850.00',
            'currency' => 'VES',
            'file' => $this->receiptImage(),
        ];
    }
}
