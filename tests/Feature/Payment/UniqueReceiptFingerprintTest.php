<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Layaway;
use App\Models\Organization;
use App\Rules\UniqueReceiptFingerprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class UniqueReceiptFingerprintTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase, UploadsReceiptFiles;

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

    public function test_the_same_file_fails(): void
    {
        $file = $this->receiptImage();
        $this->receiptFor($this->layaway, attributes: ['file_hash' => hash_file('sha256', (string) $file->getRealPath())]);

        $this->assertSame('Este comprobante ya fue reportado.', $this->failure($file, 'Banesco', '9999999999'));
    }

    public function test_the_same_bank_and_reference_fail(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertSame('Este comprobante ya fue reportado.', $this->failure($this->receiptImage(), 'Banesco', '0123456789'));
    }

    public function test_the_same_reference_in_another_bank_passes(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertNull($this->failure($this->receiptImage(), 'Mercantil', '0123456789'));
    }

    public function test_a_null_reference_never_matches(): void
    {
        $this->receiptFor($this->layaway, attributes: ['bank' => null, 'reference' => null]);

        $this->assertNull($this->failure($this->receiptImage(), null, null));
    }

    public function test_a_rejected_receipt_still_counts(): void
    {
        $file = $this->receiptImage();
        $this->receiptFor($this->layaway, ReceiptStatus::Rejected, ['file_hash' => hash_file('sha256', (string) $file->getRealPath()), 'bank' => 'Banesco', 'reference' => '0123456789']);

        $this->assertNotNull($this->failure($file, 'Mercantil', '5555555555'));
        $this->assertNotNull($this->failure($this->receiptImage(), 'Banesco', '0123456789'));
    }

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

    private function failure(UploadedFile|string $file, ?string $bank, ?string $reference, ?Organization $organization = null): ?string
    {
        return $this->withinOrganization($organization ?? $this->organization, function () use ($file, $bank, $reference): ?string {
            $validator = Validator::make(['file' => $file], ['file' => [new UniqueReceiptFingerprint($bank, $reference)]]);

            return $validator->fails() ? $validator->errors()->first('file') : null;
        });
    }
}
