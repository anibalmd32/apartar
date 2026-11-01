<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Auditing\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpdateCustomerActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    public function test_the_business_edits_name_phone_email_and_corrects_the_document(): void
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['document_number' => 'V12345678', 'full_name' => 'Maria']));

        $this->actingAs($this->member)->from(route('customers.show', $customer))->put(route('customers.update', $customer), [
            'first_name' => 'María',
            'last_name' => 'González',
            'phone' => '0412-7654321',
            'email' => 'maria@ejemplo.test',
            'document_number' => 'V99999999',
        ])->assertRedirect(route('customers.show', $customer))->assertSessionHas('status', 'Datos del cliente guardados.');

        $row = DB::table('customers')->where('id', $customer->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(['María González', '+584127654321', 'maria@ejemplo.test', 'V99999999'], [$row->full_name, $row->phone, $row->email, $row->document_number]);
        $this->assertSame(1, $this->auditEntries(AuditEvent::CustomerUpdated));

        $this->actingAs($this->member)->put(route('customers.update', $customer), ['first_name' => '', 'phone' => '12'])->assertSessionHasErrors(['first_name', 'phone']);
    }

    public function test_a_customer_without_document_gets_one_later(): void
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['document_number' => null, 'document_kind' => null]));

        $this->actingAs($this->member)->put(route('customers.update', $customer), $this->contact())->assertSessionHasNoErrors();
        $this->assertNull(DB::table('customers')->where('id', $customer->getKey())->value('document_number'));

        $this->actingAs($this->member)->put(route('customers.update', $customer), [...$this->contact(), 'document_number' => 'V-20111222'])->assertSessionHasNoErrors();

        $row = DB::table('customers')->where('id', $customer->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(['V20111222', 'cedula'], [$row->document_number, $row->document_kind]);
    }

    public function test_a_document_that_belongs_to_another_customer_is_refused(): void
    {
        [$customer, $other] = $this->withinOrganization($this->organization, fn (): array => [
            Customer::factory()->create(['document_number' => null, 'document_kind' => null]),
            Customer::factory()->create(['document_number' => 'V20111222', 'full_name' => 'Pedro Pérez']),
        ]);

        $this->actingAs($this->member)->put(route('customers.update', $customer), [...$this->contact(), 'document_number' => 'V20111222'])
            ->assertSessionHasErrors(['document_number' => 'Ya tienes un cliente con ese documento: Pedro Pérez. Si es la misma persona, fusiona las fichas.']);

        $this->assertNull(DB::table('customers')->where('id', $customer->getKey())->value('document_number'));
        $this->assertSame('V20111222', DB::table('customers')->where('id', $other->getKey())->value('document_number'));
    }

    public function test_the_name_is_edited_in_two_parts_and_the_phone_can_be_removed(): void
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['full_name' => 'María José Pérez']));

        $this->actingAs($this->member)->get(route('customers.show', $customer))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('customer.firstName', 'María José Pérez')
            ->where('customer.lastName', null));

        $this->put(route('customers.update', $customer), ['first_name' => 'María José', 'last_name' => 'Pérez', 'phone' => ''])->assertSessionHasNoErrors();

        $row = DB::table('customers')->where('id', $customer->getKey())->first(['full_name', 'first_name', 'last_name', 'phone']);
        $this->assertNotNull($row);
        $this->assertSame(['María José Pérez', 'María José', 'Pérez', null], [$row->full_name, $row->first_name, $row->last_name, $row->phone]);
        $this->get(route('customers.show', $customer))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('customer.firstName', 'María José')
            ->where('customer.lastName', 'Pérez')
            ->where('customer.phone', null));
    }

    /**
     * @return array<string, string>
     */
    private function contact(): array
    {
        return ['first_name' => 'Rosa', 'last_name' => 'Díaz', 'phone' => '0414-1112233'];
    }

    private function auditEntries(AuditEvent $event): int
    {
        return $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->audited()->ofEvent($event)->count());
    }
}
