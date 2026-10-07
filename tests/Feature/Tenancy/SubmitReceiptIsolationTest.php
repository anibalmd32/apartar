<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Models\Organization;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * La subida de comprobantes contra la organización vecina (`REQ-QAX-008`
 * RB-2, `REQ-ORG-002`, `REQ-PAY-003` RB-4, `T-059`).
 *
 * Las dos consultas del tenant que estrena la subida —el apartado que se
 * bloquea y los duplicados que se buscan— van por el global scope: con un
 * negocio fijado, lo del otro no existe ni para subir sobre ello ni para
 * bloquear una subida.
 */
final class SubmitReceiptIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
    }

    /**
     * Con nuestro negocio fijado, el apartado del vecino no existe: la
     * subida no llega a guardar nada, ni fila ni archivo.
     */
    public function test_a_receipt_cannot_be_submitted_on_the_neighbours_layaway(): void
    {
        $ours = Organization::factory()->create();
        $neighbours = $this->layawayIn($this->neighbourOrganization());
        $data = new SubmitReceiptData($neighbours, null, PaymentMethod::PagoMovil, 'Banesco', '0123456789', CarbonImmutable::now(), '850.00', Currency::VES, null, $this->receiptImage());

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->withinOrganization($ours, fn (): Receipt => app(SubmitReceiptAction::class)->handle($data));
        } finally {
            $this->assertSame(0, DB::table('receipts')->count());
            $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        }
    }

    /**
     * Lo reportado en el vecino —mismo archivo, misma referencia, aunque
     * esté rechazado— no es un duplicado para nosotros; lo nuestro sí.
     */
    public function test_the_neighbours_receipts_never_count_as_duplicates(): void
    {
        $ours = Organization::factory()->create();
        $hash = hash('sha256', 'la misma captura');
        $this->receiptFor($this->layawayIn($this->neighbourOrganization()), attributes: ['file_hash' => $hash, 'bank' => 'Banesco', 'reference' => '0123456789']);

        $action = app(SubmitReceiptAction::class);

        $this->assertNull($this->withinOrganization($ours, fn () => $action->duplicateOf($hash, 'Banesco', '0123456789')));
        $this->assertNotNull($this->withinOrganization($this->neighbourOrganization(), fn () => $action->duplicateOf($hash, 'Banesco', '0123456789')));
    }
}
