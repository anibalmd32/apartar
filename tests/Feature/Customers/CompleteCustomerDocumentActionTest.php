<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Customer\CompleteCustomerDocumentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CustomerAlreadyExistsException;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CompleteCustomerDocumentActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
    }

    public function test_it_fills_the_missing_document_and_leaves_a_trace(): void
    {
        $customer = $this->customer(null);

        app(CompleteCustomerDocumentAction::class)->handle($customer, DocumentNumber::fromInput('J-12345678-9'));

        $this->assertSame(['J123456789', 'rif'], $this->documentOf($customer));
        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->audited()->ofEvent(AuditEvent::CustomerUpdated)->sole());
        $this->assertStringContainsString(CompleteCustomerDocumentAction::PROCESS, $entry->description);
    }

    public function test_a_document_already_written_is_never_overwritten_from_the_portal(): void
    {
        $customer = $this->customer('V20111222');

        app(CompleteCustomerDocumentAction::class)->handle($customer, DocumentNumber::fromInput('V30111222'));

        $this->assertSame('V20111222', $this->documentOf($customer)[0]);
    }

    public function test_a_document_of_another_record_even_a_deleted_one_is_refused(): void
    {
        $customer = $this->customer(null);
        $deleted = $this->customer('V20111222');
        $this->withinOrganization($this->organization, fn () => $deleted->delete());

        $this->expectException(CustomerAlreadyExistsException::class);

        try {
            app(CompleteCustomerDocumentAction::class)->handle($customer, DocumentNumber::fromInput('V20111222'));
        } finally {
            $this->assertNull($this->documentOf($customer)[0]);
        }
    }

    private function customer(?string $document): Customer
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create([
            'document_number' => $document,
            'document_kind' => $document === null ? null : 'cedula',
        ]));
        $this->withoutOrganization();

        return $customer;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function documentOf(Customer $customer): array
    {
        $row = DB::table('customers')->where('id', $customer->getKey())->first(['document_number', 'document_kind']);
        $this->assertNotNull($row);

        return [$row->document_number, $row->document_kind];
    }
}
