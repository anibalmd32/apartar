<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduleAlreadyMaterializedException;
use App\Domain\Money\Money;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayCompleted;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class ActivateLayawayActionTest extends TestCase
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

    public function test_it_materializes_the_schedule_with_exact_amounts_and_dates(): void
    {
        $layaway = $this->layaway(new LayawayTerms(new Money(40_000), new Money(10_000), 1, 5, 15));

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->activate($layaway);

        $this->assertSame([
            [1, '2026-09-25', 6_000, 'pending', 0],
            [2, '2026-10-10', 6_000, 'pending', 0],
            [3, '2026-10-25', 6_000, 'pending', 0],
            [4, '2026-11-09', 6_000, 'pending', 0],
            [5, '2026-11-24', 6_000, 'pending', 0],
        ], $this->storedInstallments($layaway));

        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('active', $row->status);
        $this->assertSame('2026-09-10 10:00:00', $row->activated_at);
        $this->assertSame('2026-09-25', $row->next_due_date);
    }

    public function test_the_remainder_lands_on_the_last_installment_and_the_stored_sum_is_exact(): void
    {
        $layaway = $this->layaway(new LayawayTerms(new Money(15_000), new Money(5_000), 1, 3, 30));

        $this->activate($layaway);

        $this->assertSame([3_333, 3_333, 3_334], array_column($this->storedInstallments($layaway), 2));
        $this->assertSame(10_000, (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->sum('amount_cents'));
    }

    public function test_the_stored_sum_matches_the_whole_layaway_with_quantity(): void
    {
        $layaway = $this->layaway(new LayawayTerms(new Money(9_999), new Money(1_001), 3, 7, 7));

        $this->activate($layaway);

        $stored = (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->sum('amount_cents');
        $this->assertSame(29_997 - 3_003, $stored);
        $this->assertSame(7, DB::table('installments')->where('layaway_id', $layaway->getKey())->count());
    }

    public function test_the_schedule_counts_from_the_activation_not_from_the_creation(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 09:00:00');
        $layaway = $this->layaway();

        CarbonImmutable::setTestNow('2026-09-03 16:30:00');
        $this->activate($layaway);

        $this->assertSame('2026-09-18', $this->storedInstallments($layaway)[0][1]);
    }

    public function test_two_organizations_in_different_zones_get_different_dates_for_the_same_activation(): void
    {
        $tokyo = $this->neighbourOrganization(['timezone' => 'Asia/Tokyo']);

        $ours = $this->layaway();
        $theirs = $this->layawayIn($tokyo);

        CarbonImmutable::setTestNow('2026-09-10 22:00:00');
        $this->activate($ours);
        $this->withinOrganization($tokyo, fn () => app(ActivateLayawayAction::class)->handle($theirs));

        $this->assertSame('2026-09-25', $this->storedInstallments($ours)[0][1]);
        $this->assertSame('2026-09-26', $this->withinOrganization($tokyo, fn () => $this->storedInstallments($theirs)[0][1]));
        $this->assertSame('2026-09-26', DB::table('layaways')->where('id', $theirs->getKey())->value('next_due_date'));
    }

    public function test_the_model_passed_in_comes_back_updated(): void
    {
        $layaway = $this->layaway();

        $schedule = $this->activate($layaway);

        $this->assertSame(LayawayStatus::Active, $layaway->status);
        $this->assertNotNull($layaway->activated_at);
        $this->assertSame($schedule->firstUnpaid()?->dueDateString(), $layaway->next_due_date?->toDateString());
        $this->assertFalse($layaway->isDirty());
        $this->assertSame(5, $schedule->count());
        $this->assertSame(
            array_map(fn ($installment): int => $installment->amount->cents, $schedule->installments),
            array_column($this->storedInstallments($layaway), 2),
        );
    }

    public function test_the_installments_read_back_through_the_model(): void
    {
        $layaway = $this->layaway();
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->activate($layaway);

        $first = $this->withinOrganization($this->organization, fn () => $layaway->installments()->firstOrFail());

        $this->assertSame(1, $first->number);
        $this->assertEquals(new Money(3_000), $first->amount_cents);
        $this->assertEquals(new Money(0), $first->paid_cents);
        $this->assertSame(InstallmentStatus::Pending, $first->status);
        $this->assertSame('2026-09-25', $first->due_date->toDateString());
        $this->assertNull($first->paid_at);
        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => $first->layaway()->firstOrFail()->is($layaway)));
    }

    public function test_a_zero_financed_layaway_completes_through_active(): void
    {
        $layaway = $this->layaway(new LayawayTerms(new Money(30_000), new Money(30_000), 1, 3, 15));
        $this->setCounter(4);

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $schedule = $this->activate($layaway);

        $this->assertTrue($schedule->isEmpty());
        $this->assertSame([], $this->storedInstallments($layaway));

        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertSame('2026-09-10 10:00:00', $row->activated_at);
        $this->assertSame('2026-09-10 10:00:00', $row->completed_at);
        $this->assertNull($row->next_due_date);

        $this->assertSame([LayawayActivated::class, LayawayCompleted::class], $this->dispatchedClasses());
        $this->assertSame(LayawayStatus::PendingDownPayment, $this->dispatched[0]->from());
        $this->assertSame(LayawayStatus::Active, $this->dispatched[1]->from());
        $this->assertSame(3, $this->counter(), 'Completar tiene que liberar el cupo.');
        $this->assertSame(LayawayStatus::Completed, $layaway->status);
    }

    public function test_a_second_call_on_an_active_layaway_is_rejected_by_the_state_machine(): void
    {
        $layaway = $this->layaway();
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->activate($layaway);
        $this->dispatched = [];

        CarbonImmutable::setTestNow('2026-09-12 10:00:00');

        try {
            $this->activate($layaway);
            $this->fail('Se activó dos veces el mismo apartado.');
        } catch (IllegalLayawayTransitionException $exception) {
            $this->assertSame(LayawayStatus::Active, $exception->from);
        }

        $this->assertCount(5, $this->storedInstallments($layaway));
        $this->assertSame('2026-09-10 10:00:00', DB::table('layaways')->where('id', $layaway->getKey())->value('activated_at'));
        $this->assertSame([], $this->dispatched);
    }

    public function test_a_retry_over_a_half_inserted_schedule_does_not_duplicate(): void
    {
        $layaway = $this->layaway();
        $this->setCounter(4);
        $this->withinOrganization($this->organization, function () use ($layaway): void {
            foreach ([1, 2] as $number) {
                (new Installment)->forceFill([
                    'layaway_id' => $layaway->getKey(),
                    'number' => $number,
                    'due_date' => '2026-09-25',
                    'amount_cents' => new Money(3_000),
                ])->save();
            }
        });

        try {
            $this->activate($layaway);
            $this->fail('Se materializó un cronograma encima de otro.');
        } catch (ScheduleAlreadyMaterializedException $exception) {
            $this->assertSame($layaway->getKey(), $exception->layawayId);
            $this->assertStringContainsString('ya tiene cuotas', $exception->getMessage());
        }

        $this->assertCount(2, $this->storedInstallments($layaway));
        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('pending_down_payment', $row->status);
        $this->assertNull($row->activated_at);
        $this->assertNull($row->next_due_date);
        $this->assertSame([], $this->dispatched);
        $this->assertSame(4, $this->counter());
        $this->assertSame(LayawayStatus::PendingDownPayment, $layaway->status, 'El modelo de quien llama cuenta un estado que se deshizo.');
    }

    public function test_a_stored_schedule_that_does_not_add_up_rolls_everything_back(): void
    {
        $layaway = $this->layaway();
        DB::table('layaways')->where('id', $layaway->getKey())->update(['total_cents' => 20_001]);
        $layaway = $this->withinOrganization($this->organization, fn () => Layaway::query()->findOrFail($layaway->getKey()));

        try {
            $this->activate($layaway);
            $this->fail('Se activó un apartado cuyas cuotas no suman lo financiado.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('suman 15000 céntimos en la base y lo financiado es 15001', $exception->getMessage());
        }

        $this->assertSame([], $this->storedInstallments($layaway));
        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        $this->assertSame([], $this->dispatched);
    }

    public function test_the_layaway_is_locked_before_anything_is_written(): void
    {
        $layaway = $this->layaway();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->activate($layaway);

        $lock = $this->firstIndex($statements, fn (string $sql): bool => str_contains($sql, 'from `layaways`') && str_ends_with($sql, 'for update'));
        $firstWrite = $this->firstIndex($statements, fn (string $sql): bool => str_starts_with($sql, 'update `layaways`') || str_starts_with($sql, 'insert into `installments`'));

        $this->assertNotNull($lock, 'La activación no bloqueó el apartado.');
        $this->assertNotNull($firstWrite);
        $this->assertLessThan($firstWrite, $lock);
    }

    public function test_an_installment_amount_cannot_be_changed_but_its_due_date_can(): void
    {
        $layaway = $this->layaway();
        $this->activate($layaway);

        $this->withinOrganization($this->organization, function () use ($layaway): void {
            $installment = $layaway->installments()->firstOrFail();

            $installment->forceFill(['due_date' => '2026-12-01'])->save();
            $this->assertSame('2026-12-01', $installment->fresh()?->due_date->toDateString());

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('inmutable en importe y se intentó cambiar: amount_cents');

            $installment->forceFill(['amount_cents' => new Money(1)])->save();
        });
    }

    public function test_activation_leaves_the_down_payment_applied_through_the_recalculator(): void
    {
        $layaway = $this->layaway();

        $this->activate($layaway);

        $row = DB::table('layaways')->where('id', $layaway->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame(5_000, (int) $row->paid_cents);
        $this->assertSame(15_000, (int) $row->remaining_cents);
        $this->assertSame(5_000, $layaway->paid_cents->cents);
    }

    private function layaway(?LayawayTerms $terms = null): Layaway
    {
        return $this->layawayIn($this->organization, terms: $terms);
    }

    private function activate(Layaway $layaway): AmortizationSchedule
    {
        return $this->withinOrganization($this->organization, fn (): AmortizationSchedule => app(ActivateLayawayAction::class)->handle($layaway));
    }

    /**
     * @return list<array{0: int, 1: string, 2: int, 3: string, 4: int}>
     */
    private function storedInstallments(Layaway $layaway): array
    {
        return DB::table('installments')
            ->where('layaway_id', $layaway->getKey())
            ->orderBy('number')
            ->get()
            ->map(fn (object $row): array => [(int) $row->number, (string) $row->due_date, (int) $row->amount_cents, (string) $row->status, (int) $row->paid_cents])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function dispatchedClasses(): array
    {
        return array_map(fn (LayawayTransitioned $event): string => $event::class, $this->dispatched);
    }

    /**
     * @param  list<string>  $statements
     * @param  callable(string): bool  $matches
     */
    private function firstIndex(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
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
