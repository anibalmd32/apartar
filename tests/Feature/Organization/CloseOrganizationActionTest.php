<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CloseOrganizationAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationHasActiveLayawaysException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CloseOrganizationActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_closing_with_layaways_in_progress_is_rejected_with_the_list(): void
    {
        $pending = $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment);
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $overdue = $this->layawayIn($this->organization, LayawayStatus::Overdue);
        $this->layawayIn($this->organization, LayawayStatus::Completed);
        $catalog = Catalog::factory()->published()->create();

        try {
            $this->close();
            $this->fail('Con apartados en curso no se cierra.');
        } catch (OrganizationHasActiveLayawaysException $exception) {
            $this->assertSame([$pending->code, $active->code, $overdue->code], $exception->codes);
        }

        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
        $this->assertNotSame(SubscriptionStatus::Cancelled, Subscription::query()->firstOrFail()->status);
        $this->assertNotNull($catalog->fresh()?->published_at);
        $this->assertSame(0, AuditEntry::query()->ofEvent(AuditEvent::OrganizationClosed)->count());
    }

    public function test_without_layaways_in_progress_it_closes_keeping_the_data(): void
    {
        $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        $catalog = Catalog::factory()->published()->create();

        $this->close();

        $organization = Organization::query()->withTrashed()->findOrFail($this->organization->getKey());
        $this->assertSame(OrganizationStatus::Closed, $organization->status);
        $this->assertNull($organization->deleted_at, 'La eliminación definitiva es manual y de plataforma.');
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::query()->firstOrFail()->status);
        $this->assertNull($catalog->fresh()?->published_at);

        $entry = AuditEntry::query()->ofEvent(AuditEvent::OrganizationClosed)->sole();
        $this->assertSame($this->owner->getKey(), $entry->causer_id);
        $this->assertSame([$catalog->slug], $entry->properties['changed']['unpublished_catalogs']['after'] ?? null);
    }

    public function test_a_member_cannot_close(): void
    {
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        try {
            app(CloseOrganizationAction::class)->handle($member);
        } finally {
            $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
        }
    }

    public function test_closing_twice_is_harmless(): void
    {
        $this->close();
        $this->close();

        $this->assertSame(1, AuditEntry::query()->ofEvent(AuditEvent::OrganizationClosed)->count());
    }

    public function test_the_scheduled_downgrade_is_dropped(): void
    {
        Subscription::query()->update(['status' => SubscriptionStatus::Grace, 'scheduled_plan_id' => Subscription::query()->value('plan_id'), 'scheduled_plan_at' => now()->addWeek()]);

        $this->close();

        $subscription = Subscription::query()->firstOrFail();
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_plan_at);
    }

    private function close(): Organization
    {
        return app(CloseOrganizationAction::class)->handle($this->owner);
    }
}
