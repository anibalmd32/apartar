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

/**
 * El punto de extensión de RB-6 (`REQ-ORG-007`): una cuenta de cobro con
 * comprobantes no se borra.
 *
 * `UpsertPaymentAccountAction` pregunta, degrada el borrado y tiene su test
 * —con esta clase sustituida en el contenedor—. Lo que se comprueba aquí es la
 * consulta: desde `T-054`, con comprobantes de verdad.
 *
 * Hasta `T-054` este test afirmaba que la tabla no existía y que la clase
 * **lanzaba** en cuanto existiera sin cuerpo: la lección de
 * `PublishedCatalogs::exist()` en `T-014`. La tabla y el cuerpo llegaron en la
 * misma tarea, que es lo que esa guarda pedía.
 */
final class RecordedReceiptsTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * Una cuenta a la que un cliente dijo haber pagado tiene comprobantes,
     * también si el comprobante se rechazó; la que nadie usó, no.
     */
    public function test_an_account_with_receipts_has_them_and_one_without_does_not(): void
    {
        [$used, $unused] = $this->withinOrganization($this->organization, fn (): array => [PaymentAccount::factory()->create(), PaymentAccount::factory()->create()]);
        $this->receiptFor($this->layawayIn($this->organization), ReceiptStatus::Rejected, ['payment_account_id' => $used->getKey()]);

        $this->withinOrganization($this->organization, function () use ($used, $unused): void {
            $this->assertTrue((new RecordedReceipts)->existFor($used));
            $this->assertFalse((new RecordedReceipts)->existFor($unused));
        });
    }

    /**
     * Y la base lo respalda: la clave es `restrict`, así que ni un `DELETE`
     * directo se lleva una cuenta con comprobantes.
     */
    public function test_the_database_refuses_to_delete_an_account_with_receipts(): void
    {
        $account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->create());
        $this->receiptFor($this->layawayIn($this->organization), attributes: ['payment_account_id' => $account->getKey()]);

        $this->expectException(QueryException::class);

        DB::table('payment_accounts')->where('id', $account->getKey())->delete();
    }

    /**
     * Y se puede sustituir, que es lo que permitió ejercitar la degradación
     * del borrado antes de que existiera el modelo.
     */
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
