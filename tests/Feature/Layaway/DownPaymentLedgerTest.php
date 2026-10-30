<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Domain\Layaway\DownPaymentLedger;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\PaymentApplier;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DownPaymentLedgerTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = Organization::factory()->create();
        $this->staff = $this->within(fn (): User => User::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_adds_what_each_payment_left_for_the_down_payment_and_nothing_of_the_installments(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $ledger = new DownPaymentLedger;

        $this->assertSame(0, $this->within(fn (): int => $ledger->paidOn($layaway)));

        $first = $this->pay($layaway, 2_000);
        $second = $this->pay($layaway, 1_500);
        $third = $this->pay($layaway, 9_000);
        $fourth = $this->pay($layaway, 3_000);

        $this->assertSame(5_000, $this->within(fn (): int => $ledger->paidOn($layaway)));
        $this->assertSame(
            [2_000, 3_500, 5_000, 5_000],
            $this->within(fn (): array => array_map(fn (AppliedPayment $applied): int => $ledger->paidThrough($applied->payment), [$first, $second, $third, $fourth])),
        );
    }

    public function test_it_sums_several_layaways_at_once_and_skips_those_without_payments(): void
    {
        $partial = $this->layawayIn($this->organization);
        $settled = $this->layawayIn($this->organization);
        $untouched = $this->layawayIn($this->organization);
        $this->pay($partial, 1_000);
        $this->pay($settled, 8_000);

        $paid = $this->within(fn (): array => (new DownPaymentLedger)->paidOnEach([(int) $partial->getKey(), (int) $settled->getKey(), (int) $untouched->getKey()]));

        $this->assertSame([(int) $partial->getKey() => 1_000, (int) $settled->getKey() => 5_000], $paid);
        $this->assertSame([], (new DownPaymentLedger)->paidOnEach([]));
    }

    private function pay(Layaway $layaway, int $cents): AppliedPayment
    {
        return $this->within(fn (): AppliedPayment => app(PaymentApplier::class)->apply(
            $layaway,
            new Money($cents),
            null,
            number_format($cents / 100, 2, '.', ''),
            Currency::USD,
            null,
            $this->staff,
            'Abono en tienda.',
        ));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
