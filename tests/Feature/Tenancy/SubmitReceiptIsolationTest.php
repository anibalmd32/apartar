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

final class SubmitReceiptIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
    }

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
