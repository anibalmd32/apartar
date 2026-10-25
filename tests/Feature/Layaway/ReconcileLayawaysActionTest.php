<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ReconcileLayawaysAction;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\ReconciliationReport;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ReconcileLayaways;
use App\Models\AuditEntry;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReconcileLayawaysActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        Cache::forget(ReconciliationReport::CACHE_KEY);
        $this->organization = $this->subscribe(Organization::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_injected_discrepancy_is_reported_and_then_fixed(): void
    {
        $layaway = $this->paidLayaway($this->organization);
        $payments = DB::table('payments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 9_000, 'remaining_cents' => 11_000]);

        $this->artisan('layaways:reconcile')
            ->expectsOutputToContain('1 apartados y 0 productos con faltante revisados; 1 descuadres.')
            ->assertExitCode(1);
        $this->assertSame([9_000, 11_000], $this->balanceOf($layaway));

        $this->artisan('layaways:reconcile', ['--fix' => true])
            ->expectsOutputToContain('(1 corregidos, 0 bloqueados, 0 por revisar, 0 fallidos)')
            ->assertExitCode(0);
        $this->assertSame([11_000, 9_000], $this->balanceOf($layaway));
        $this->assertSame($payments, DB::table('payments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());

        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReconciliationCorrected->value)->sole());
        $this->assertNull($entry->causer_id);
        $this->assertSame(['after' => 11_000, 'before' => 9_000], $entry->properties?->toArray()['changed']['paid_cents'] ?? null);

        $this->artisan('layaways:reconcile')->assertExitCode(0);
    }

    public function test_installments_are_rebuilt_from_allocations_and_a_ledger_gap_is_left_for_a_person(): void
    {
        $layaway = $this->paidLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->update(['paid_cents' => 0, 'status' => 'pending', 'paid_at' => null]);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 8_000, 'remaining_cents' => 12_000]);

        CarbonImmutable::setTestNow('2026-09-20 15:00:00');
        $report = $this->reconcile(fix: true);

        $this->assertSame(['fixed'], array_column($report->entries(), 'outcome'));
        $this->assertSame('2026-09-10 10:00:00', DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->value('paid_at'));
        $changed = $this->withinOrganization($this->organization, fn (): array => AuditEntry::query()->where('event', AuditEvent::ReconciliationCorrected->value)->sole()->properties?->toArray()['changed'] ?? []);
        $this->assertSame([0, 3_000], [$changed['installments_paid_cents']['before']['cuota_2'] ?? null, $changed['installments_paid_cents']['after']['cuota_2'] ?? null]);
        $this->assertSame([[2, 0, 3_000]], array_map(fn (array $drift): array => array_values($drift), $report->entries()[0]['installments']));
        $this->assertSame(['3000', 'paid'], array_values(array_map('strval', (array) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->first(['paid_cents', 'status']))));
        $this->assertSame([11_000, 9_000], $this->balanceOf($layaway));

        DB::table('payments')->where('layaway_id', $layaway->getKey())->orderByDesc('id')->limit(1)->update(['unallocated_cents' => 500]);

        $this->assertSame(['unresolved'], array_column($this->reconcile(fix: true)->entries(), 'outcome'));
        $this->artisan('layaways:reconcile', ['--fix' => true])->assertExitCode(1);
    }

    public function test_a_terminal_layaway_with_drifted_installments_is_left_for_a_person(): void
    {
        $layaway = $this->paidLayaway($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => LayawayStatus::Cancelled->value]);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->update(['paid_cents' => 0]);
        $installments = DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['paid_cents', 'status', 'paid_at'])->map(fn (object $row): array => (array) $row)->all();

        $this->assertSame(['unresolved'], array_column($this->reconcile(fix: true)->entries(), 'outcome'));

        $this->assertSame($installments, DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['paid_cents', 'status', 'paid_at'])->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame('cancelled', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    public function test_a_status_that_should_have_moved_is_reported_and_fixed(): void
    {
        $layaway = $this->paidLayaway($this->organization);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 3)->update(['status' => 'overdue']);

        $this->assertSame(['status' => ['stored' => 'active', 'derived' => 'overdue']], $this->reconcile(fix: false)->entries()[0]['fields']);

        $this->reconcile(fix: true);

        $this->assertSame('overdue', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    public function test_terminal_and_suspended_layaways_are_reconciled_too(): void
    {
        $cancelled = $this->paidLayaway($this->organization);
        DB::table('layaways')->where('id', $cancelled->getKey())->update(['status' => LayawayStatus::Cancelled->value, 'remaining_cents' => 1]);
        $suspended = $this->subscribe(Organization::factory()->create());
        $theirs = $this->paidLayaway($suspended);
        $suspended->forceFill(['status' => OrganizationStatus::Suspended])->save();
        DB::table('layaways')->where('id', $theirs->getKey())->update(['remaining_cents' => 1]);

        $this->artisan('layaways:reconcile')->assertExitCode(1);
        $this->assertSame([1, 1], [$this->balanceOf($cancelled)[1], $this->balanceOf($theirs)[1]]);

        $this->artisan('layaways:reconcile', ['--fix' => true])->assertExitCode(0);
        $this->assertSame([9_000, 9_000], [$this->balanceOf($cancelled)[1], $this->balanceOf($theirs)[1]]);
        $this->assertSame('cancelled', DB::table('layaways')->where('id', $cancelled->getKey())->value('status'));
    }

    public function test_the_organization_option_narrows_the_pass(): void
    {
        $ours = $this->paidLayaway($this->organization);
        $neighbour = $this->subscribe(Organization::factory()->create());
        $theirs = $this->paidLayaway($neighbour);
        DB::table('layaways')->whereIn('id', [$ours->getKey(), $theirs->getKey()])->update(['remaining_cents' => 1]);

        $this->artisan('layaways:reconcile', ['--fix' => true, '--organization' => (string) $this->organization->getKey()])->assertExitCode(0);

        $this->assertSame([9_000, 1], [$this->balanceOf($ours)[1], $this->balanceOf($theirs)[1]]);
        $this->assertNull(ReconciliationReport::last());
        $this->artisan('layaways:reconcile', ['--organization' => '999999'])->assertExitCode(1);
        $this->artisan('layaways:reconcile', ['--organization' => '1.5'])->expectsOutputToContain('No existe la organización «1.5».')->assertExitCode(1);
    }

    public function test_a_shortfall_above_the_reserved_units_is_capped(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('products')->where('id', $layaway->product_id)->update(['stock' => 0, 'stock_shortfall' => 3]);

        $report = $this->reconcile(fix: false);
        $this->assertSame([['product', 3, 1, 'reported']], array_map(fn (array $entry): array => [$entry['type'], $entry['stock_shortfall'], $entry['reserved_units'], $entry['outcome']], $report->entries()));

        $this->reconcile(fix: true);

        $this->assertSame(1, (int) DB::table('products')->where('id', $layaway->product_id)->value('stock_shortfall'));
        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->where('event', AuditEvent::ReconciliationCorrected->value)->sole());
        $this->assertSame(Product::class, $entry->subject_type);
        $this->assertSame(['after' => 1, 'before' => 3], $entry->properties?->toArray()['changed']['stock_shortfall'] ?? null);
        $this->assertSame(1, $entry->properties?->toArray()['changed']['reserved_units']['after'] ?? null);
    }

    public function test_it_makes_the_same_queries_for_three_layaways_or_nine(): void
    {
        $count = function (int $layaways): int {
            DB::table('payment_allocations')->delete();
            DB::table('payments')->delete();
            DB::table('installments')->delete();
            DB::table('layaways')->delete();

            for ($i = 0; $i < $layaways; $i++) {
                $layaway = $this->paidLayaway($this->organization);
                DB::table('layaways')->where('id', $layaway->getKey())->update(['remaining_cents' => 1]);
                DB::table('products')->where('id', $layaway->product_id)->update(['stock' => 0, 'stock_shortfall' => 5]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->reconcile(fix: false);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(3), $count(9));
    }

    public function test_the_job_visits_each_organization_with_its_tenant_and_leaves_the_summary(): void
    {
        $ours = $this->paidLayaway($this->organization);
        $neighbour = $this->subscribe(Organization::factory()->create());
        $theirs = $this->paidLayaway($neighbour);
        DB::table('layaways')->whereIn('id', [$ours->getKey(), $theirs->getKey()])->update(['remaining_cents' => 1]);
        $this->withoutOrganization();

        $report = app()->call([new ReconcileLayaways(fix: true), 'handle']);

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
        $this->assertInstanceOf(ReconciliationReport::class, $report);
        $this->assertSame(
            [[(int) $this->organization->getKey(), (int) $ours->getKey()], [(int) $neighbour->getKey(), (int) $theirs->getKey()]],
            DB::table('activity_log')->where('event', AuditEvent::ReconciliationCorrected->value)->orderBy('id')->get(['organization_id', 'subject_id'])->map(fn (object $row): array => [(int) $row->organization_id, (int) $row->subject_id])->all(),
        );
        $this->assertNull(ReconciliationReport::last());

        DB::table('layaways')->where('id', $ours->getKey())->update(['remaining_cents' => 1]);
        app()->call([new ReconcileLayaways, 'handle']);
        $this->assertSame(['discrepancies' => 1, 'stock' => 0, 'unresolved' => 0, 'lastRunAt' => CarbonImmutable::now()->toIso8601String()], ReconciliationReport::last());
    }

    public function test_a_second_pass_does_not_run_while_one_is_in_progress(): void
    {
        $lock = Cache::lock(ReconcileLayaways::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertNull(app()->call([new ReconcileLayaways, 'handle']));
            $this->artisan('layaways:reconcile')->expectsOutputToContain('Hay otra pasada de la reconciliación en curso')->assertExitCode(1);
        } finally {
            $lock->release();
        }

        $this->assertInstanceOf(ReconciliationReport::class, app()->call([new ReconcileLayaways, 'handle']));
    }

    public function test_it_is_scheduled_daily_at_half_past_five(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'layaways:reconcile');

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('30 5 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function reconcile(bool $fix): ReconciliationReport
    {
        return $this->withinOrganization($this->organization, fn (): ReconciliationReport => app(ReconcileLayawaysAction::class)->handle($fix, CarbonImmutable::now()));
    }

    private function paidLayaway(Organization $organization): Layaway
    {
        $layaway = $this->layawayIn($organization);
        $staff = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        foreach (['50.00', '60.00'] as $amount) {
            $this->withinOrganization($organization, fn () => app(RegisterManualPaymentAction::class)->handle(
                $layaway,
                new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), 'Pago recibido en tienda por Ana.', (string) Str::uuid()),
                $staff,
            ));
        }

        $this->withoutOrganization();

        return $layaway;
    }

    /**
     * @return array{int, int}
     */
    private function balanceOf(Layaway $layaway): array
    {
        $row = DB::table('layaways')->where('id', $layaway->getKey())->first(['paid_cents', 'remaining_cents']);
        $this->assertNotNull($row);

        return [(int) $row->paid_cents, (int) $row->remaining_cents];
    }
}
