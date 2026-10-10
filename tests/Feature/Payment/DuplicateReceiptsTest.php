<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceipts;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La regla de duplicados de `REQ-PAY-003` RB-2 en un solo sitio (`T-069`):
 * el archivo primero, después la pareja banco + referencia, sin referencia no
 * hay criterio, y en la organización del tenant —un duplicado del vecino no
 * es duplicado aquí (RB-4)—.
 */
final class DuplicateReceiptsTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_two_criteria_in_order_and_only_within_the_tenant(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $hash = hash('sha256', 'comprobante');
        $this->withinOrganization($ours, fn () => Receipt::factory()->create(['layaway_id' => $this->layawayIn($ours), 'file_hash' => $hash, 'bank' => 'Banesco', 'reference' => '0001']));

        $this->withinOrganization($ours, function () use ($hash): void {
            $this->assertSame(DuplicateCriterion::FileHash, DuplicateReceipts::criterionFor(Receipt::query(), $hash, 'Banesco', '0001'), 'El archivo va primero.');
            $this->assertSame(DuplicateCriterion::BankReference, DuplicateReceipts::criterionFor(Receipt::query(), hash('sha256', 'otro'), 'Banesco', '0001'));
            $this->assertNull(DuplicateReceipts::criterionFor(Receipt::query(), hash('sha256', 'otro'), 'Banesco', null), 'Sin referencia no hay criterio (RB-3).');
        });

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $this->assertNull($this->withinOrganization($neighbour, fn (): ?DuplicateCriterion => DuplicateReceipts::criterionFor(Receipt::query(), $hash, 'Banesco', '0001')));
    }

    /** La carrera de SP-5: el único de la tabla dice cuál de los dos ganó; otro único no es un duplicado. */
    public function test_it_reads_the_violated_unique_of_each_table(): void
    {
        $violation = fn (string $index): UniqueConstraintViolationException => new UniqueConstraintViolationException('mysql', 'insert', [], new RuntimeException("Duplicate entry for key '{$index}'"));

        $this->assertSame(DuplicateCriterion::FileHash, DuplicateReceipts::fromViolation($violation('subscription_receipts_organization_id_file_hash_unique'), 'subscription_receipts'));
        $this->assertSame(DuplicateCriterion::BankReference, DuplicateReceipts::fromViolation($violation('receipts_organization_id_bank_reference_unique'), 'receipts'));
        $this->assertNull(DuplicateReceipts::fromViolation($violation('receipts_organization_id_file_hash_unique'), 'subscription_receipts'), 'El único de otra tabla no cuenta.');
    }
}
