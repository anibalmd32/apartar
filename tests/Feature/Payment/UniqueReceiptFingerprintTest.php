<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Layaway;
use App\Models\Organization;
use App\Rules\UniqueReceiptFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * `UniqueReceiptFingerprint` (`T-059`, `REQ-PAY-003`): la primera barrera
 * contra el comprobante repetido, la que responde con un `422` antes de
 * guardar nada.
 *
 * Contra MySQL y no en `tests/Unit` porque lo que se prueba es una consulta
 * del tenant: que mire los dos criterios, que cuente los rechazados y que
 * **solo vea su organización**. Se pasa por el validador de Laravel, como la
 * usará el formulario, sobre el atributo `file`.
 */
final class UniqueReceiptFingerprintTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);
    }

    public function test_a_new_file_and_reference_pass(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '1111111111']);

        $this->assertNull($this->failure($this->receiptImage(), 'Banesco', '2222222222'));
    }

    /**
     * (a) El mismo archivo, aunque cambie la referencia. Con el mensaje de
     * RB-6, sin más.
     */
    public function test_the_same_file_fails(): void
    {
        $file = $this->receiptImage();
        $this->receiptFor($this->layaway, attributes: ['file_hash' => hash_file('sha256', (string) $file->getRealPath())]);

        $this->assertSame('Este comprobante ya fue reportado.', $this->failure($file, 'Banesco', '9999999999'));
    }

    /**
     * (b) La misma pareja banco y referencia, aunque el archivo sea otro
     * (SP-3: la captura recortada).
     */
    public function test_the_same_bank_and_reference_fail(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertSame('Este comprobante ya fue reportado.', $this->failure($this->receiptImage(), 'Banesco', '0123456789'));
    }

    /**
     * La misma referencia en **otro** banco es otro pago.
     */
    public function test_the_same_reference_in_another_bank_passes(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertNull($this->failure($this->receiptImage(), 'Mercantil', '0123456789'));
    }

    /**
     * RB-3 · sin referencia no hay criterio (b): el efectivo no choca con
     * otro efectivo.
     */
    public function test_a_null_reference_never_matches(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => null, 'reference' => null]);

        $this->assertNull($this->failure($this->receiptImage(), null, null));
    }

    /**
     * RB-5 · el rechazado sigue contando, por los dos criterios.
     */
    public function test_a_rejected_receipt_still_counts(): void
    {
        $file = $this->receiptImage();
        $this->receiptFor($this->layaway, ReceiptStatus::Rejected, ['file_hash' => hash_file('sha256', (string) $file->getRealPath()), 'bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertNotNull($this->failure($file, 'Mercantil', '5555555555'));
        $this->assertNotNull($this->failure($this->receiptImage(), 'Banesco', '0123456789'));
    }

    /**
     * **RB-4 · solo ve su organización.** El mismo archivo y la misma
     * referencia, reportados en el negocio vecino, pasan aquí; y desde el
     * vecino, fallan.
     */
    public function test_it_only_sees_the_organization_that_is_set(): void
    {
        $file = $this->receiptImage();
        $this->receiptFor($this->layawayIn($this->neighbourOrganization()), attributes: [
            'file_hash' => hash_file('sha256', (string) $file->getRealPath()),
            'bank' => 'Banesco',
            'reference' => '0123456789',
        ]);

        $this->assertNull($this->failure($file, 'Banesco', '0123456789'));
        $this->assertNotNull($this->failure($file, 'Banesco', '0123456789', $this->neighbourOrganization()));
    }

    /**
     * Sin organización fijada no mira «en todas»: lanza.
     */
    public function test_it_refuses_to_run_without_a_tenant(): void
    {
        $this->withoutOrganization();
        $this->expectException(MissingTenantContextException::class);

        Validator::make(['file' => $this->receiptImage()], ['file' => [new UniqueReceiptFingerprint('Banesco', '1')]])->passes();
    }

    public function test_it_leaves_what_is_neither_a_file_nor_a_text_to_their_rules(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $validator = $this->withinOrganization($this->organization, fn () => Validator::make(
            ['file' => ['0123456789']],
            ['file' => [new UniqueReceiptFingerprint('Banesco', '0123456789')]],
        ));

        $this->assertFalse($validator->fails());
    }

    /**
     * `T-059` · sobre la referencia misma, la regla mira la pareja con el
     * banco que se le da, para que el formulario devuelva ese duplicado en
     * su campo; con otro banco, es otro pago.
     */
    public function test_on_the_reference_itself_it_checks_the_pair(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $check = fn (string $bank): bool => $this->withinOrganization($this->organization, fn (): bool => Validator::make(
            ['reference' => ' 0123456789 '],
            ['reference' => [new UniqueReceiptFingerprint($bank, null)]],
        )->fails());

        $this->assertTrue($check('Banesco'));
        $this->assertFalse($check('Mercantil'));
    }

    // -------------------------------------------------------------------

    /**
     * El mensaje con que falla `file` bajo esa organización, o `null` si pasa.
     */
    private function failure(UploadedFile|string $file, ?string $bank, ?string $reference, ?Organization $organization = null): ?string
    {
        return $this->withinOrganization($organization ?? $this->organization, function () use ($file, $bank, $reference): ?string {
            $validator = Validator::make(['file' => $file], ['file' => [new UniqueReceiptFingerprint($bank, $reference)]]);

            return $validator->fails() ? $validator->errors()->first('file') : null;
        });
    }
}
