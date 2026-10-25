<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\LinkAccountAction;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Account\LinkOrigin;
use App\Domain\Auditing\AuditEvent;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LinkAccountActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->account = Account::factory()->create();
    }

    public function test_it_links_only_the_account_and_audits_without_the_account(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $customer = Customer::factory()->create();
            $before = $customer->only(['full_name', 'document_number', 'phone', 'email']);

            $this->assertTrue($this->link($customer, LinkOrigin::VerifiedClaim));

            $fresh = Customer::query()->findOrFail($customer->getKey());
            $this->assertSame((int) $this->account->getKey(), (int) $fresh->account_id);
            $this->assertSame($before, $fresh->only(['full_name', 'document_number', 'phone', 'email']));

            $entry = AuditEntry::query()->ofEvent(AuditEvent::AccountLinked)->sole();
            $this->assertSame((int) $this->organization->getKey(), (int) $entry->organization_id);
            $this->assertNull($entry->causer_id);
            $properties = $entry->properties?->toArray() ?? [];
            $this->assertSame('verified_claim', $properties['changed']['origin']['after'] ?? null);
            $this->assertArrayNotHasKey('account_id', $properties['changed'] ?? []);
            $this->assertStringNotContainsString($this->account->email, (string) json_encode($properties));
        });
    }

    public function test_linking_again_to_the_same_account_is_a_no_op(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            $customer = Customer::factory()->create(['account_id' => $this->account->getKey()]);

            $this->assertFalse($this->link($customer, LinkOrigin::LayawayCreation));
            $this->assertSame(0, AuditEntry::query()->ofEvent(AuditEvent::AccountLinked)->count());
        });
    }

    public function test_a_record_of_another_account_is_never_transferred(): void
    {
        $other = Account::factory()->create();

        $this->withinOrganization($this->organization, function () use ($other): void {
            $customer = Customer::factory()->create(['account_id' => $other->getKey()]);

            $this->expectLinkConflict($customer, CustomerAlreadyLinkedException::TO_ANOTHER_ACCOUNT);
            $this->assertSame((int) $other->getKey(), (int) Customer::query()->findOrFail($customer->getKey())->account_id);
        });
    }

    public function test_one_record_per_account_and_business(): void
    {
        $this->withinOrganization($this->organization, function (): void {
            Customer::factory()->create(['account_id' => $this->account->getKey()]);
            $second = Customer::factory()->create();

            $this->expectLinkConflict($second, CustomerAlreadyLinkedException::ACCOUNT_HAS_ANOTHER_RECORD);
        });

        $this->withinOrganization(Organization::factory()->create(), function (): void {
            $this->assertTrue($this->link(Customer::factory()->create(), LinkOrigin::VerifiedClaim));
        });
    }

    private function link(Customer $customer, LinkOrigin $origin): bool
    {
        return app(LinkAccountAction::class)->handle($customer, $this->account, $origin);
    }

    private function expectLinkConflict(Customer $customer, string $message): void
    {
        try {
            $this->link($customer, LinkOrigin::VerifiedClaim);
            $this->fail('Vinculó lo que no debía.');
        } catch (CustomerAlreadyLinkedException $conflict) {
            $this->assertSame($message, $conflict->getMessage());
        }
    }
}
