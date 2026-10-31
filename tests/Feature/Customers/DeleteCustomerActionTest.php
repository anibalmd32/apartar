<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\LayawayStatus;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DeleteCustomerActionTest extends TestCase
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

    public function test_a_customer_without_layaways_is_deleted_for_good(): void
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create(['full_name' => 'Luis Rojas']));

        $this->actingAs($this->member)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', 'Cliente Luis Rojas eliminado.');

        $this->assertDatabaseMissing('customers', ['id' => $customer->getKey()]);
        $this->assertSame(1, $this->auditEntries(AuditEvent::CustomerDeleted));
    }

    public function test_a_customer_with_only_finished_layaways_is_hidden_and_keeps_the_history(): void
    {
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        $customerId = $cancelled->customer_id;
        $this->withoutOrganization();

        $this->actingAs($this->member)->delete(route('customers.destroy', $customerId))->assertRedirect(route('customers.index'));

        $this->assertNotNull(DB::table('customers')->where('id', $customerId)->value('deleted_at'));
        $this->assertSame($customerId, $this->withinOrganization($this->organization, fn (): ?int => Layaway::query()->findOrFail($cancelled->getKey())->customer?->getKey()));
        $this->actingAs($this->member)->get(route('customers.show', $customerId))->assertNotFound();
        $this->actingAs($this->member)->get(route('customers.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0));
        $this->actingAs($this->member)->get(route('layaways.show', $cancelled))->assertOk();
    }

    public function test_a_customer_with_a_live_layaway_is_not_deleted(): void
    {
        $live = $this->layawayIn($this->organization);
        $this->withoutOrganization();

        $this->actingAs($this->member)
            ->from(route('customers.show', $live->customer_id))
            ->delete(route('customers.destroy', $live->customer_id))
            ->assertSessionHasErrors(['customer' => 'Este cliente tiene un apartado en curso. Cancélalo o espera a que termine para poder eliminarlo.']);

        $this->assertNull(DB::table('customers')->where('id', $live->customer_id)->value('deleted_at'));
        $this->actingAs($this->member)->get(route('customers.show', $live->customer_id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('manage.liveLayaways', 1));
    }

    public function test_a_deleted_customer_who_reserves_again_gets_the_same_record_back(): void
    {
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        DB::table('customers')->where('id', $cancelled->customer_id)->update(['document_number' => 'V12345678']);
        $this->withoutOrganization();
        $this->actingAs($this->member)->delete(route('customers.destroy', $cancelled->customer_id));

        $again = $this->withinOrganization($this->organization, fn (): Customer => app(ResolveCustomerAction::class)->handle(
            CustomerContactData::fromInput('V-12.345.678', 'María González', '0414-1234567', null),
            TermsConsent::acceptedNowFrom('190.202.10.5'),
        ));

        $this->assertSame($cancelled->customer_id, $again->getKey());
        $this->assertNull(DB::table('customers')->where('id', $cancelled->customer_id)->value('deleted_at'));
    }

    public function test_adding_by_hand_a_deleted_document_reactivates_it(): void
    {
        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        DB::table('customers')->where('id', $cancelled->customer_id)->update(['document_number' => 'V23456789']);
        $this->withoutOrganization();
        $this->actingAs($this->member)->delete(route('customers.destroy', $cancelled->customer_id));

        $this->actingAs($this->member)->post(route('customers.store'), [
            'full_name' => 'Luis Rojas',
            'document_number' => 'V-23456789',
            'phone' => '0414-1112233',
            'terms_accepted' => true,
        ])->assertRedirect(route('customers.show', $cancelled->customer_id));

        $this->assertNull(DB::table('customers')->where('id', $cancelled->customer_id)->value('deleted_at'));
        $this->assertSame('Luis Rojas', DB::table('customers')->where('id', $cancelled->customer_id)->value('full_name'));
    }

    public function test_another_business_customer_cannot_be_edited_or_deleted(): void
    {
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->member)->put(route('customers.update', $foreign), ['full_name' => 'X', 'phone' => '0414-1112233'])->assertNotFound();
        $this->actingAs($this->member)->delete(route('customers.destroy', $foreign))->assertNotFound();
    }

    private function auditEntries(AuditEvent $event): int
    {
        return $this->withinOrganization($this->organization, fn (): int => AuditEntry::query()->audited()->ofEvent($event)->count());
    }
}
