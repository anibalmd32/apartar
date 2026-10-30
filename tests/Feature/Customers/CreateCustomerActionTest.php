<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CreateCustomerActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['name' => 'Ana Pérez']));
        $this->withoutOrganization();
    }

    public function test_the_business_adds_a_customer_with_declared_consent(): void
    {
        $response = $this->actingAs($this->member)->post(route('customers.store'), $this->payload());

        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->sole());

        $response->assertRedirect(route('customers.show', $customer))->assertSessionHas('status', 'Cliente Luis Rojas agregado.');
        $this->assertSame(['Luis Rojas', 'V23456789', '+584141112233', 'luis@ejemplo.test'], [$customer->full_name, $customer->document_number, $customer->phone, $customer->email]);
        $this->assertSame($this->member->getKey(), $customer->terms_declared_by_user_id);
        $this->assertNotNull($customer->terms_accepted_at);
        $this->assertNotNull($customer->terms_ip_address);

        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->audited()->ofEvent(AuditEvent::CustomerCreated)->sole());
        $this->assertSame($customer->getKey(), $entry->subject_id);
    }

    public function test_the_email_is_optional(): void
    {
        $this->actingAs($this->member)->post(route('customers.store'), [...$this->payload(), 'email' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->withinOrganization($this->organization, fn (): ?string => Customer::query()->sole()->email));
    }

    public function test_a_document_already_in_the_directory_is_refused_without_touching_the_existing_customer(): void
    {
        $existing = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create([
            'document_number' => 'V23456789',
            'full_name' => 'Luisa Rojas',
            'phone' => '+584121234567',
        ]));

        $this->actingAs($this->member)
            ->post(route('customers.store'), $this->payload())
            ->assertSessionHasErrors(['document_number' => 'Ya tienes un cliente con ese documento: Luisa Rojas.']);

        $fresh = $this->withinOrganization($this->organization, fn (): ?Customer => $existing->fresh());
        $this->assertSame(['Luisa Rojas', '+584121234567', null], [$fresh?->full_name, $fresh?->phone, $fresh?->terms_declared_by_user_id]);
        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->audited()->ofEvent(AuditEvent::CustomerCreated)->count()));
    }

    public function test_the_same_document_in_another_business_does_not_collide(): void
    {
        $this->withinOrganization($this->neighbourOrganization(), fn () => Customer::factory()->create(['document_number' => 'V23456789']));

        $this->actingAs($this->member)->post(route('customers.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withinOrganization($this->organization, fn (): int => Customer::query()->count()));
    }

    public function test_the_consent_must_be_declared_and_the_contact_valid(): void
    {
        $this->actingAs($this->member)
            ->post(route('customers.store'), [...$this->payload(), 'terms_accepted' => false, 'document_number' => 'X1', 'phone' => '123', 'full_name' => ''])
            ->assertSessionHasErrors(['terms_accepted', 'document_number', 'phone', 'full_name']);

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => Customer::query()->count()));
    }

    public function test_a_suspended_business_cannot_add_customers(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->actingAs($this->member)->post(route('customers.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => Customer::query()->count()));
    }

    public function test_a_disabled_member_cannot_add_customers(): void
    {
        $this->withinOrganization($this->organization, fn () => $this->member->forceFill(['disabled_at' => now()])->save());

        $this->actingAs($this->member)->post(route('customers.store'), $this->payload());

        $this->assertSame(0, $this->withinOrganization($this->organization, fn (): int => Customer::query()->count()));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'full_name' => 'Luis Rojas',
            'document_number' => 'V-23456789',
            'phone' => '0414-1112233',
            'email' => 'luis@ejemplo.test',
            'terms_accepted' => true,
        ];
    }
}
