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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * `DetectOverdueAction` contra la base de datos (`REQ-LAY-007`).
 *
 * El apartado de casi todos los casos es el del *happy path* de
 * `REQ-LAY-004`: activado el 10/09 en Caracas, cinco cuotas de $30 cada 15
 * días, la primera el 25/09. Con la gracia por defecto de 3 días, la cuota 1
 * está en mora a partir del 29/09.
 */
final class DetectOverdueActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

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

    /**
     * RB-1 al día exacto: el 28/09 (vencimiento + 3) todavía no; el 29/09 sí,
     * y el apartado pasa a `overdue` con su evento (RB-2).
     */
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

    /**
     * RB-4 y SP-2: $10 pagados de $30 no sacan a nadie de la mora; dentro de
     * la gracia, la cuota está a medio pagar.
     */
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

    /**
     * SP-1: una cuota cubierta no entra en mora aunque su fecha pase, y no
     * arrastra al apartado.
     */
    public function test_a_covered_installment_never_becomes_overdue(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);

        $summary = $this->detect('2026-09-29 06:00:00');

        $this->assertSame('paid', $this->statusOfInstallment($layaway, 1));
        $this->assertSame('active', $this->statusOf($layaway));
        $this->assertEquals(new OverdueSummary, $summary);
    }

    /**
     * SP-3: ampliar la gracia de 3 a 7 días devuelve la cuota a `pending` y el
     * apartado a `active`, con `LayawayCaughtUp`.
     */
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

    /**
     * SP-4: un apartado terminal con cuotas sin pagar no se toca: ni sus
     * cuotas ni su estado.
     */
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

    /**
     * RB-5 y SP-5: dos pasadas el mismo día dejan lo mismo que una, y un día
     * sin job se recupera en el siguiente.
     */
    public function test_it_is_idempotent_and_recovers_a_missed_day(): void
    {
        $layaway = $this->activeLayaway($this->organization);

        // No corrió el 29 ni el 30: el 1/10 recoge la cuota 1.
        $first = $this->detect('2026-10-01 06:00:00');
        $second = $this->detect('2026-10-01 07:00:00');

        $this->assertSame(1, $first->installmentsMarkedOverdue);
        $this->assertEquals(new OverdueSummary, $second);
        $this->assertSame('overdue', $this->statusOfInstallment($layaway, 1));
        $this->assertCount(1, $this->dispatched);
    }

    /**
     * RB-5 y SP-6, el caso explícito: tres negocios evaluados en el mismo
     * instante, el 25/09 a las 12:00 de Caracas —ya la 01:00 del 26 en
     * Tokio—. Con gracia 0, el de Tokio está en mora y el de Caracas no: la
     * diferencia es la zona. Con gracia 3, tampoco el segundo de Caracas.
     */
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

        // SP-7: con gracia 0, Caracas entra en mora el día siguiente al vencimiento.
        $this->withinOrganization($caracasNoGrace, fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-26 06:00:00')));
        $this->assertSame('overdue', $this->statusOf($inCaracas));
    }

    /**
     * RB-8: con la organización suspendida, la mora se detecta igual.
     */
    public function test_it_runs_with_the_organization_suspended(): void
    {
        $layaway = $this->activeLayaway($this->organization);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->detect('2026-09-29 06:00:00');

        $this->assertSame('overdue', $this->statusOf($layaway));
    }

    /**
     * Los dos *scopes* del modelo que usa la pasada.
     */
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
