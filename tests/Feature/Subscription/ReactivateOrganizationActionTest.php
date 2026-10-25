<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Organization\ReactivateOrganizationAction;
use App\Actions\Organization\SuspendOrganizationAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ReactivateOrganizationActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        Notification::fake();

        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_lifts_the_suspension_without_republishing(): void
    {
        $catalog = Catalog::factory()->published()->create();
        app(SuspendOrganizationAction::class)->handle($this->organization);
        Subscription::query()->update(['grace_ends_at' => CarbonImmutable::parse('2026-11-08 23:59:59')]);

        $this->assertTrue($this->reactivate());

        $organization = $this->organization->fresh();
        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(OrganizationStatus::Active, $organization?->status);
        $this->assertNull($organization->suspended_at);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertNull($catalog->fresh()?->published_at, 'Republicar es decisión del owner.');

        $entry = AuditEntry::query()->ofEvent(AuditEvent::SubscriptionReactivated)->sole();
        $this->assertSame(['after' => 'active', 'before' => 'suspended'], $entry->properties['changed']['organization'] ?? null);
    }

    public function test_a_scheduled_downgrade_comes_back_as_grace(): void
    {
        $target = Plan::factory()->create(['code' => 'destino']);
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended, 'scheduled_plan_id' => $target->getKey(), 'scheduled_plan_at' => now()->addWeek()]);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->reactivate();

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Grace, $subscription->status);
        $this->assertSame($target->getKey(), $subscription->scheduled_plan_id);
    }

    public function test_without_a_suspension_it_does_nothing(): void
    {
        $this->assertFalse($this->reactivate());
        $this->assertSame(0, AuditEntry::query()->ofEvent(AuditEvent::SubscriptionReactivated)->count());
    }

    public function test_a_half_suspended_organization_is_lifted_too(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->assertTrue($this->reactivate());
        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
    }

    public function test_a_closed_organization_or_a_cancelled_subscription_is_not_reactivated(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->assertFalse($this->reactivate());

        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();
        Subscription::query()->update(['status' => SubscriptionStatus::Suspended]);
        $this->assertFalse($this->reactivate());
        $this->assertSame(OrganizationStatus::Closed, $this->organization->fresh()?->status);
    }

    private function reactivate(): bool
    {
        return app(ReactivateOrganizationAction::class)->handle($this->organization);
    }
}
