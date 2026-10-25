<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SubscriptionPolicyTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_it_is_discovered_by_convention(): void
    {
        $this->assertInstanceOf(SubscriptionPolicy::class, Gate::getPolicyFor(Subscription::class));
    }

    public function test_only_the_owner_governs_the_subscription(): void
    {
        [$owner, $member, $subscription] = $this->scenario();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('changePlan', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('viewInvoices', $subscription));
        $this->assertTrue(Gate::forUser($owner)->allows('uploadPaymentProof', $subscription));

        $this->assertFalse(Gate::forUser($member)->allows('view', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('changePlan', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('viewInvoices', $subscription));
        $this->assertFalse(Gate::forUser($member)->allows('uploadPaymentProof', $subscription));
    }

    public function test_an_owner_does_not_govern_the_subscription_next_door(): void
    {
        [$owner] = $this->scenario();

        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): Subscription => Subscription::factory()->create(),
        );

        $this->withoutOrganization();

        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreign));
        $this->assertFalse(Gate::forUser($owner)->allows('changePlan', $foreign));
    }

    /**
     * @return array{0: User, 1: User, 2: Subscription}
     */
    private function scenario(): array
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        /** @var array{0: User, 1: User, 2: Subscription} $scenario */
        $scenario = $this->withinOrganization($organization, fn (): array => [
            User::factory()->state(['role' => OrganizationRole::Owner])->create(),
            User::factory()->state(['role' => OrganizationRole::Member])->create(),
            Subscription::factory()->create(),
        ]);

        $this->withoutOrganization();

        return $scenario;
    }
}
