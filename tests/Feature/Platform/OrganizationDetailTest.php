<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\Plan;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OrganizationDetailTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_the_detail_carries_the_limits_and_the_business_profile(): void
    {
        [$organization, $owner] = $this->businessWithOwner(Plan::factory()->create(['max_products' => 300]));
        $organization->forceFill(['phone' => '0212-555-8899', 'document' => 'J-50112003-9'])->save();
        $this->asOperator();

        $this->get(route('platform.organizations.show', $organization))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('limits.products', 300)
            ->where('profile.ownerName', $owner->name)
            ->where('profile.ownerEmail', $owner->email)
            ->where('profile.phone', '0212-555-8899')
            ->where('profile.document', 'J-50112003-9')
            ->where('suspendable', true));
    }

    public function test_an_operator_suspends_by_hand(): void
    {
        [$organization] = $this->businessWithOwner();
        $this->asOperator();

        $this->post(route('platform.organizations.pause', $organization))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(OrganizationStatus::Suspended, $organization->refresh()->status);
        $this->get(route('platform.organizations.show', $organization))->assertInertia(fn (AssertableInertia $page) => $page->where('suspendable', false));
    }

    public function test_the_shell_knows_the_operator_and_the_pending_receipts(): void
    {
        $this->asOperator();

        $this->get(route('platform.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platformOperator.name', fn (string $name): bool => $name !== '')
            ->where('platformOperator.pendingReceipts', 0));
    }

    public function test_a_guest_cannot_suspend(): void
    {
        $organization = Organization::factory()->create();

        $this->post(route('platform.organizations.pause', $organization))->assertRedirect();

        $this->assertSame(OrganizationStatus::Active, $organization->refresh()->status);
    }
}
