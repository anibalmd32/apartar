<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayBecameOverdue;
use App\Events\Layaway\LayawayCaughtUp;
use App\Events\Layaway\LayawayCompleted;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class LayawayStateMachineTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    /** @var list<LayawayTransitioned> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

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
     * @return array<string, array{0: LayawayStatus, 1: LayawayStatus}>
     */
    public static function everyCell(): array
    {
        $cells = [];

        foreach (LayawayStatus::cases() as $from) {
            foreach (LayawayStatus::cases() as $to) {
                $cells["{$from->value} → {$to->value}"] = [$from, $to];
            }
        }

        return $cells;
    }

    #[DataProvider('everyCell')]
    public function test_the_matrix_holds_cell_by_cell(LayawayStatus $from, LayawayStatus $to): void
    {
        $layaway = $this->layawayIn($this->organization, $from);
        $this->setCounter(5);
        $this->dispatched = [];
        $before = $this->row($layaway);

        if ($from->canTransitionTo($to)) {
            $this->transition($layaway, $to);

            $this->assertSame($to, $this->fresh($layaway)->status);
            $this->assertCount(1, $this->dispatched);

            return;
        }

        try {
            $this->transition($layaway, $to);
            $this->fail("{$from->value} → {$to->value} no es una transición válida y la máquina lo dejó pasar.");
        } catch (IllegalLayawayTransitionException $exception) {
            $this->assertStringContainsString("de «{$from->value}» a «{$to->value}»", $exception->getMessage());
        }

        $this->assertSame($before, $this->row($layaway), 'Una transición ilegal dejó algo escrito.');
        $this->assertSame(5, $this->counter(), 'Una transición ilegal movió el cupo.');
        $this->assertSame([], $this->dispatched, 'Una transición ilegal emitió un evento.');
    }

    public function test_each_transition_writes_its_own_mark(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 14:00:00');
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->assertSame('2026-09-10 14:00:00', $this->fresh($layaway)->activated_at?->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2026-09-30 09:00:00');
        $this->transition($layaway, LayawayStatus::Overdue);
        CarbonImmutable::setTestNow('2026-10-02 09:00:00');
        $this->transition($layaway, LayawayStatus::Active);

        $this->assertSame('2026-09-10 14:00:00', $this->fresh($layaway)->activated_at?->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2026-11-24 18:30:00');
        $this->transition($layaway, LayawayStatus::Completed);

        $this->assertSame('2026-11-24 18:30:00', $this->fresh($layaway)->completed_at?->format('Y-m-d H:i:s'));

        $cancelled = $this->layawayIn($this->organization, LayawayStatus::Active);
        CarbonImmutable::setTestNow('2026-12-01 10:00:00');
        $this->transition($cancelled, LayawayStatus::Cancelled);

        $this->assertSame('2026-12-01 10:00:00', $this->fresh($cancelled)->cancelled_at?->format('Y-m-d H:i:s'));
    }

    public function test_expiring_leaves_the_deadline_alone(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => '2026-09-13 14:00:00']);

        $this->transition($layaway, LayawayStatus::Expired);

        $fresh = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::Expired, $fresh->status);
        $this->assertSame('2026-09-13 14:00:00', $fresh->expires_at?->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->activated_at);
    }

    public function test_each_transition_emits_its_event(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->dispatched = [];

        foreach ([LayawayStatus::Active, LayawayStatus::Overdue, LayawayStatus::Active, LayawayStatus::Completed] as $to) {
            $this->transition($layaway, $to);
        }

        $this->assertSame(
            [LayawayActivated::class, LayawayBecameOverdue::class, LayawayCaughtUp::class, LayawayCompleted::class],
            array_map(fn (LayawayTransitioned $event): string => $event::class, $this->dispatched),
        );

        $completed = $this->dispatched[3];
        $this->assertSame($layaway->getKey(), $completed->layawayId());
        $this->assertSame($this->organization->getKey(), $completed->organizationId());
        $this->assertSame(LayawayStatus::Active, $completed->from());
        $this->assertSame(LayawayStatus::Completed, $completed->to());
    }

    public function test_the_model_passed_in_comes_back_updated(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->transition($layaway, LayawayStatus::Active);

        $this->assertSame(LayawayStatus::Active, $layaway->status);
        $this->assertNotNull($layaway->activated_at);
        $this->assertFalse($layaway->isDirty());
    }

    public function test_the_plan_slot_moves_only_across_the_terminal_boundary(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->setCounter(3);

        $this->transition($layaway, LayawayStatus::Active);
        $this->transition($layaway, LayawayStatus::Overdue);
        $this->transition($layaway, LayawayStatus::Active);
        $this->assertSame(3, $this->counter(), 'Moverse entre estados vivos no consume ni libera cupo.');

        $this->transition($layaway, LayawayStatus::Completed);
        $this->assertSame(2, $this->counter(), 'Completar tiene que liberar el cupo.');

        foreach ([LayawayStatus::Cancelled, LayawayStatus::Expired] as $terminal) {
            $other = $this->layawayIn($this->organization);
            $before = $this->counter();

            $this->transition($other, $terminal);

            $this->assertSame($before - 1, $this->counter(), "Pasar a «{$terminal->value}» tiene que liberar el cupo.");
        }
    }

    public function test_releasing_with_the_counter_at_zero_stays_at_zero(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->setCounter(0);
        $logger = Log::spy();

        $this->transition($layaway, LayawayStatus::Cancelled);

        $this->assertSame(LayawayStatus::Cancelled, $this->fresh($layaway)->status);
        $this->assertSame(0, $this->counter());
        $logger->shouldHaveReceived('warning')->with('usage_counter.below_zero', Mockery::on(
            fn (array $context): bool => $context['metric'] === UsageMetric::ActiveLayaways->value
                && $context['previous'] === 0
                && $context['delta'] === -1,
        ));
    }

    public function test_completed_prevails_over_overdue(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Overdue);

        $this->transition($layaway, LayawayStatus::Completed);

        $this->expectException(IllegalLayawayTransitionException::class);

        try {
            $this->transition($layaway, LayawayStatus::Overdue);
        } finally {
            $this->assertSame(LayawayStatus::Completed, $this->fresh($layaway)->status);
        }
    }

    public function test_overdue_and_active_go_back_and_forth(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        foreach ([LayawayStatus::Overdue, LayawayStatus::Active, LayawayStatus::Overdue, LayawayStatus::Active] as $to) {
            $this->transition($layaway, $to);
            $this->assertSame($to, $this->fresh($layaway)->status);
        }
    }

    public function test_an_expired_layaway_cannot_be_activated(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Expired);

        $this->expectException(IllegalLayawayTransitionException::class);
        $this->expectExceptionMessage('«expired» es un estado terminal');

        $this->transition($layaway, LayawayStatus::Active);
    }

    public function test_a_completed_layaway_cannot_be_cancelled(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);

        $this->expectException(IllegalLayawayTransitionException::class);
        $this->expectExceptionMessage('no puede pasar de «completed» a «cancelled»');

        $this->transition($layaway, LayawayStatus::Cancelled);
    }

    public function test_a_failing_listener_does_not_revert_the_transition(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->setCounter(4);

        $callerLevel = DB::transactionLevel();
        $listenerLevel = null;

        Event::listen(LayawayCompleted::class, function () use (&$listenerLevel): void {
            $listenerLevel = DB::transactionLevel();

            throw new RuntimeException('el servidor de correo no responde');
        });

        try {
            $this->transition($layaway, LayawayStatus::Completed);
            $this->fail('El listener que falla no llegó a correr.');
        } catch (RuntimeException $exception) {
            $this->assertSame('el servidor de correo no responde', $exception->getMessage());
        }

        $this->assertSame($callerLevel, $listenerLevel, 'El listener corrió dentro de la transacción de la transición, no después del commit.');

        $fresh = $this->fresh($layaway);
        $this->assertSame(LayawayStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(3, $this->counter());
    }

    public function test_a_rolled_back_transition_emits_nothing(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->setCounter(4);
        $this->dispatched = [];

        try {
            DB::transaction(function () use ($layaway): void {
                $this->transition($layaway, LayawayStatus::Completed);

                throw new RuntimeException('falla el paso siguiente de la acción');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->dispatched);
        $this->assertSame(LayawayStatus::Active, $this->fresh($layaway)->status);
        $this->assertSame(4, $this->counter());
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $suspended = Organization::factory()->suspended()->create();
        $this->withinOrganization($suspended, fn () => Subscription::factory()->forPlan(Plan::factory()->create())->suspended()->create());

        $layaway = $this->layawayIn($suspended);
        $this->withinOrganization($suspended, fn () => UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => 2, 'computed_at' => now()]));

        $this->withinOrganization($suspended, function () use ($layaway): void {
            app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Active);
            app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Completed);
        });

        $this->assertSame(LayawayStatus::Completed, $this->withinOrganization($suspended, fn () => $layaway->fresh()?->status));
        $this->assertSame(1, $this->withinOrganization($suspended, fn () => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value')));
    }

    public function test_writing_the_status_by_hand_throws(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);

        $attempts = [
            'asignar y guardar' => function (Layaway $l): void {
                $l->status = LayawayStatus::Completed;
                $l->save();
            },
            'forceFill()' => fn (Layaway $l) => $l->forceFill(['status' => 'completed'])->save(),
        ];

        $this->assertFalse((new Layaway)->isFillable('status'), 'status no puede ser asignable en masa.');

        foreach ($attempts as $how => $attempt) {
            $this->withinOrganization($this->organization, function () use ($attempt, $how, $layaway): void {
                $model = Layaway::query()->findOrFail($layaway->getKey());

                try {
                    $attempt($model);
                    $this->fail("«{$how}» escribió el estado fuera de la máquina.");
                } catch (LogicException $exception) {
                    $this->assertStringContainsString('solo lo escribe LayawayStateMachine::transition()', $exception->getMessage());
                }
            });
        }

        $this->assertSame(LayawayStatus::Active, $this->fresh($layaway)->status);
    }

    public function test_other_columns_still_save_normally(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->withinOrganization($this->organization, function () use ($layaway): void {
            $model = Layaway::query()->findOrFail($layaway->getKey());
            $model->forceFill(['extended_at' => now()])->save();
        });

        $this->assertNotNull($this->fresh($layaway)->extended_at);
    }

    public function test_a_layaway_cannot_be_born_in_another_status(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Un apartado nace en «pending_down_payment»');

        $this->withinOrganization($this->organization, function (): void {
            (new Layaway)->forceFill(['status' => LayawayStatus::Active, 'code' => 'AP-NOPUEDE'])->save();
        });
    }

    private function transition(Layaway $layaway, LayawayStatus $to): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayStateMachine::class)->transition($layaway, $to));
    }

    private function fresh(Layaway $layaway): Layaway
    {
        /** @var Layaway */
        return $this->withinOrganization($this->organization, fn () => Layaway::query()->findOrFail($layaway->getKey()));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Layaway $layaway): array
    {
        return (array) DB::table('layaways')->where('id', $layaway->getKey())->first();
    }

    private function setCounter(int $value): void
    {
        $this->withinOrganization($this->organization, function () use ($value): void {
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::ActiveLayaways], ['value' => $value, 'computed_at' => now()]);
        });
    }

    private function counter(): int
    {
        return (int) $this->withinOrganization(
            $this->organization,
            fn () => UsageCounter::query()->where('metric', UsageMetric::ActiveLayaways)->value('value'),
        );
    }
}
