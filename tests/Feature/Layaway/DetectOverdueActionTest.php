<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\OverdueSummary;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\Layaway\LayawayBecameOverdue;
use App\Events\Layaway\LayawayCaughtUp;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DetectOverdueActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    /** @var list<LayawayTransitioned> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);

        Event::listen(LayawayTransitioned::class, function (LayawayTransitioned $transition): void {
            $this->dispatched[] = $transition;
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_grace_period_is_respected_to_the_exact_day(): void
    {
        $layaway = $this->activeLayaway($this->organization);

        $this->detect('2026-09-28 06:00:00');
        $this->assertSame('pending', $this->statusOfInstallment($layaway, 1));
        $this->assertSame('active', $this->statusOf($layaway));

        $summary = $this->detect('2026-09-29 06:00:00');

        $this->assertSame('overdue', $this->statusOfInstallment($layaway, 1));
        $this->assertSame('pending', $this->statusOfInstallment($layaway, 2));
        $this->assertSame('overdue', $this->statusOf($layaway));
        $this->assertSame([LayawayBecameOverdue::class], $this->dispatchedClasses());
        $this->assertSame(1, $summary->installmentsMarkedOverdue);
        $this->assertSame(1, $summary->layawaysBecameOverdue);
    }

    public function test_a_partial_payment_stays_overdue(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 1_000, 'status' => 'partially_paid']);

        $this->detect('2026-09-27 06:00:00');
        $this->assertSame('partially_paid', $this->statusOfInstallment($layaway, 1));

        $this->detect('2026-09-29 06:00:00');
        $this->assertSame('overdue', $this->statusOfInstallment($layaway, 1));
        $this->assertSame(1_000, (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->value('paid_cents'));
        $this->assertSame('overdue', $this->statusOf($layaway));
    }

    public function test_a_covered_installment_never_becomes_overdue(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);

        $summary = $this->detect('2026-09-29 06:00:00');

        $this->assertSame('paid', $this->statusOfInstallment($layaway, 1));
        $this->assertSame('active', $this->statusOf($layaway));
        $this->assertEquals(new OverdueSummary, $summary);
    }

    public function test_widening_the_grace_returns_installments_to_pending(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        $this->detect('2026-09-29 06:00:00');
        $this->dispatched = [];

        $this->setGrace($this->organization, 7);
        $summary = $this->detect('2026-09-30 06:00:00');

        $this->assertSame('pending', $this->statusOfInstallment($layaway, 1));
        $this->assertSame('active', $this->statusOf($layaway));
        $this->assertSame([LayawayCaughtUp::class], $this->dispatchedClasses());
        $this->assertSame(1, $summary->installmentsReturned);
        $this->assertSame(1, $summary->layawaysCaughtUp);
        $this->assertNotNull(DB::table('layaways')->where('id', $layaway->getKey())->value('activated_at'));
    }

    public function test_terminal_layaways_are_ignored(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Completed));
        $this->dispatched = [];

        $summary = $this->detect('2026-12-31 06:00:00');

        $this->assertSame('completed', $this->statusOf($layaway));
        $this->assertSame(['pending'], DB::table('installments')->where('layaway_id', $layaway->getKey())->distinct()->pluck('status')->all());
        $this->assertSame([], $this->dispatched);
        $this->assertEquals(new OverdueSummary, $summary);
    }

    public function test_it_is_idempotent_and_recovers_a_missed_day(): void
    {
        $layaway = $this->activeLayaway($this->organization);

        $first = $this->detect('2026-10-01 06:00:00');
        $second = $this->detect('2026-10-01 07:00:00');

        $this->assertSame(1, $first->installmentsMarkedOverdue);
        $this->assertEquals(new OverdueSummary, $second);
        $this->assertSame('overdue', $this->statusOfInstallment($layaway, 1));
        $this->assertCount(1, $this->dispatched);
    }

    public function test_two_organizations_with_different_graces_and_zones_are_evaluated_separately(): void
    {
        $tokyo = $this->neighbourOrganization(['timezone' => 'Asia/Tokyo']);
        $caracasNoGrace = Organization::factory()->create(['timezone' => 'America/Caracas']);
        $this->setGrace($tokyo, 0);
        $this->setGrace($caracasNoGrace, 0);

        $inTokyo = $this->activeLayaway($tokyo, activatedAt: '2026-09-10 08:00:00');
        $inCaracas = $this->activeLayaway($caracasNoGrace, activatedAt: '2026-09-10 08:00:00');
        $withGrace = $this->activeLayaway($this->organization, activatedAt: '2026-09-10 08:00:00');

        $now = CarbonImmutable::parse('2026-09-25 12:00:00');

        foreach ([$tokyo, $caracasNoGrace, $this->organization] as $organization) {
            $this->withinOrganization($organization, fn () => app(DetectOverdueAction::class)->handle($now));
        }

        $this->assertSame('overdue', $this->statusOf($inTokyo));
        $this->assertSame('active', $this->statusOf($inCaracas));
        $this->assertSame('active', $this->statusOf($withGrace));

        $this->withinOrganization($caracasNoGrace, fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-26 06:00:00')));
        $this->assertSame('overdue', $this->statusOf($inCaracas));
    }

    public function test_is_past_grace_is_the_same_rule_for_one_installment(): void
    {
        $tokyo = $this->neighbourOrganization(['timezone' => 'Asia/Tokyo']);
        $inCaracas = $this->activeLayaway($this->organization);
        $inTokyo = $this->activeLayaway($tokyo);

        $first = fn (Organization $organization, Layaway $layaway): Installment => $this->withinOrganization($organization, fn (): Installment => Installment::query()
            ->where('layaway_id', $layaway->getKey())->where('number', 1)->firstOrFail());
        $pastGrace = fn (Organization $organization, Layaway $layaway, string $instant): bool => $this->withinOrganization($organization, fn (): bool => app(DetectOverdueAction::class)
            ->isPastGrace($first($organization, $layaway), CarbonImmutable::parse($instant)));

        $this->assertFalse($pastGrace($this->organization, $inCaracas, '2026-09-28 23:59:59'));
        $this->assertTrue($pastGrace($this->organization, $inCaracas, '2026-09-29 00:00:00'));
        $this->assertTrue($pastGrace($tokyo, $inTokyo, '2026-09-28 12:00:00'));
    }

    public function test_it_runs_with_the_organization_suspended(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->detect('2026-09-29 06:00:00');

        $this->assertSame('overdue', $this->statusOf($layaway));
    }

    public function test_the_installment_scopes_select_what_their_names_say(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'overdue']);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->update(['paid_cents' => 3_000, 'status' => 'paid']);

        [$overdue, $unpaidBefore] = $this->withinOrganization($this->organization, fn (): array => [
            Installment::query()->overdue()->pluck('number')->all(),
            Installment::query()->unpaidDueBefore('2026-10-26')->orderBy('number')->pluck('number')->all(),
        ]);

        $this->assertSame([1], $overdue);
        $this->assertSame([1, 3], $unpaidBefore, 'La cuota 2 está pagada y la 4 vence después.');
    }

    private function detect(string $instant): OverdueSummary
    {
        return $this->withinOrganization(
            $this->organization,
            fn (): OverdueSummary => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse($instant)),
        );
    }

    private function activeLayaway(Organization $organization, string $activatedAt = '2026-09-10 10:00:00'): Layaway
    {
        $layaway = $this->layawayIn($organization);

        CarbonImmutable::setTestNow($activatedAt);
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        CarbonImmutable::setTestNow();
        $this->dispatched = [];

        return $layaway;
    }

    private function setGrace(Organization $organization, int $days): void
    {
        $this->withinOrganization($organization, function () use ($organization, $days): void {
            OrganizationSetting::query()->where('key', SettingKey::GracePeriodDays)->delete();
            OrganizationSetting::factory()->forKey(SettingKey::GracePeriodDays, $days)->create();
            app(Settings::class)->forget($organization);
        });
    }

    private function statusOf(Layaway $layaway): string
    {
        return (string) DB::table('layaways')->where('id', $layaway->getKey())->value('status');
    }

    private function statusOfInstallment(Layaway $layaway, int $number): string
    {
        return (string) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', $number)->value('status');
    }

    /**
     * @return list<string>
     */
    private function dispatchedClasses(): array
    {
        return array_map(fn (LayawayTransitioned $event): string => $event::class, $this->dispatched);
    }
}
