<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Organization\SuspendOrganizationAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionSuspendedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SuspendOrganizationActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        Notification::fake();

        $this->organization = Organization::factory()->create();
        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();
        $this->owner = User::factory()->owner()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_suspends_and_withdraws_every_published_catalog(): void
    {
        $live = Catalog::factory()->published()->create();
        $expired = Catalog::factory()->expired()->create();
        $draft = Catalog::factory()->create(['published_at' => null]);
        $this->recalculateUsage();

        $this->assertTrue($this->suspend());

        $this->assertSame(OrganizationStatus::Suspended, $this->organization->fresh()?->status);
        $this->assertEquals(CarbonImmutable::now(), $this->organization->fresh()->suspended_at);
        $this->assertSame(SubscriptionStatus::Suspended, Subscription::query()->firstOrFail()->status);
        $this->assertNull($live->fresh()?->published_at);
        $this->assertNull($expired->fresh()?->published_at);
        $this->assertNotNull($expired->fresh()?->expires_at, 'La fecha de fin se conserva para que el owner decida al volver.');
        $this->assertEquals($draft->updated_at, $draft->fresh()?->updated_at);
        $this->assertSame(0, $this->catalogsCounter());
    }

    public function test_it_leaves_every_layaway_and_installment_exactly_as_it_was(): void
    {
        $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->layawayIn($this->organization, LayawayStatus::PendingDownPayment);
        CarbonImmutable::setTestNow('2026-11-11 08:00:00');
        $before = $this->snapshot();

        $this->suspend();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_is_audited_with_the_catalogs_it_withdrew(): void
    {
        $catalog = Catalog::factory()->published()->create();

        $this->suspend();

        $entry = AuditEntry::query()->ofEvent(AuditEvent::SubscriptionSuspended)->sole();
        $this->assertNull($entry->causer_id);
        $this->assertStringContainsString('subscriptions:process-dunning', $entry->description);
        $this->assertSame([$catalog->slug], $entry->properties['changed']['unpublished_catalogs']['after'] ?? null);
        $this->assertSame(['after' => 'suspended', 'before' => 'active'], $entry->properties['changed']['organization'] ?? null);
    }

    public function test_suspending_twice_changes_nothing_the_second_time(): void
    {
        $this->suspend();
        CarbonImmutable::setTestNow('2026-11-12 12:00:00');

        $this->assertFalse($this->suspend());

        $this->assertEquals(CarbonImmutable::parse('2026-11-10 12:00:00'), $this->organization->fresh()?->suspended_at);
        $this->assertSame(1, AuditEntry::query()->ofEvent(AuditEvent::SubscriptionSuspended)->count());
        Notification::assertSentToTimes($this->owner, SubscriptionSuspendedNotification::class, 1);
    }

    public function test_a_closed_organization_or_a_cancelled_subscription_is_not_suspended(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Closed])->save();
        $this->assertFalse($this->suspend());
        $this->assertSame(OrganizationStatus::Closed, $this->organization->fresh()?->status);

        $this->organization->forceFill(['status' => OrganizationStatus::Active])->save();
        Subscription::query()->update(['status' => SubscriptionStatus::Cancelled]);
        $this->assertFalse($this->suspend());
        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
        $this->assertSame(0, AuditEntry::query()->ofEvent(AuditEvent::SubscriptionSuspended)->count());
    }

    public function test_the_scheduled_downgrade_and_the_grace_end_survive(): void
    {
        $target = Plan::factory()->create(['code' => 'destino']);
        Subscription::query()->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => CarbonImmutable::parse('2026-11-08 23:59:59'),
            'scheduled_plan_id' => $target->getKey(),
            'scheduled_plan_at' => CarbonImmutable::parse('2026-11-30 00:00:00'),
        ]);

        $this->suspend();

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame($target->getKey(), $subscription->scheduled_plan_id);
        $this->assertEquals(CarbonImmutable::parse('2026-11-08 23:59:59'), $subscription->grace_ends_at);
    }

    public function test_every_active_owner_is_told(): void
    {
        $second = User::factory()->owner()->create();
        $disabled = User::factory()->owner()->create(['disabled_at' => now()]);
        $member = User::factory()->create();

        $this->suspend();

        Notification::assertSentTo([$this->owner, $second], SubscriptionSuspendedNotification::class);
        Notification::assertNotSentTo([$disabled, $member], SubscriptionSuspendedNotification::class);
    }

    public function test_a_failure_rolls_everything_back_and_tells_nobody(): void
    {
        Catalog::factory()->published()->create();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update `catalogs`')) {
                throw new RuntimeException('falla al retirar');
            }
        });

        try {
            $this->suspend();
            $this->fail('Tenía que lanzar.');
        } catch (RuntimeException) {
        }

        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::query()->firstOrFail()->status);
        Notification::assertNothingSent();
    }

    public function test_the_neighbours_catalogs_stay_published(): void
    {
        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, fn (): Catalog => Catalog::factory()->published()->create());

        $this->suspend();

        $this->assertNotNull($this->withinOrganization($neighbour, fn (): ?Catalog => Catalog::query()->find($theirs->getKey()))?->published_at);
    }

    private function suspend(): bool
    {
        return app(SuspendOrganizationAction::class)->handle($this->organization);
    }

    private function catalogsCounter(): int
    {
        return (int) DB::table('usage_counters')
            ->where('organization_id', $this->organization->getKey())
            ->where('metric', 'catalogs')
            ->value('value');
    }

    /**
     * @return array{layaways: list<array<string, mixed>>, installments: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            'layaways' => DB::table('layaways')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'installments' => DB::table('installments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
