<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Organization\SuspendOrganizationAction;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\Subscription;
use App\Support\Auditing\PlatformAudit;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OrganizationReactivationTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_an_operator_lifts_a_manual_suspension(): void
    {
        [$organization] = $this->businessWithOwner();
        $operator = $this->asOperator();
        app(SuspendOrganizationAction::class)->handle($organization, $operator);

        $this->get($this->url($organization))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reactivable', true)
            ->where('suspendable', false));

        $this->post($this->url($organization).'/reactivar')->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Organización reactivada'));

        $this->assertSame(OrganizationStatus::Active, $organization->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $this->subscriptionOf($organization)->status);
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::ORGANIZATION_REACTIVATED)->where('platform_user_id', $operator->getKey())->count());
        $this->get($this->url($organization))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reactivable', false)
            ->where('suspendable', true));
    }

    public function test_an_active_organization_is_not_touched(): void
    {
        [$organization] = $this->businessWithOwner();
        $this->asOperator();

        $this->get($this->url($organization))->assertInertia(fn (AssertableInertia $page) => $page->where('reactivable', false));
        $this->post($this->url($organization).'/reactivar')->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'No estaba suspendida'));
        $this->assertSame(0, PlatformActivity::query()->where('action', PlatformAudit::ORGANIZATION_REACTIVATED)->count());
    }

    public function test_a_guest_cannot_reactivate(): void
    {
        [$organization] = $this->businessWithOwner();

        $this->post($this->url($organization).'/reactivar')->assertRedirect('/plataforma/login');
    }

    private function url(Organization $organization): string
    {
        return '/plataforma/organizaciones/'.$organization->slug;
    }

    private function subscriptionOf(Organization $organization): Subscription
    {
        $subscription = $this->withinOrganization($organization, fn (): Subscription => Subscription::query()->sole());
        $this->withoutOrganization();

        return $subscription;
    }
}
