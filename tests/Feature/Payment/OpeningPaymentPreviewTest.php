<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Exchange\ManualRate;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\ApprovalPreview;
use App\Domain\Payment\OpeningPaymentPreview;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OpeningPaymentPreviewTest extends TestCase
{
    use ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-27 10:00:00');
        $this->actingAsOrganization(Organization::factory()->create());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_covers_the_down_payment_and_spreads_the_rest_over_the_installments(): void
    {
        $preview = $this->preview(new LayawayTerms(new Money(20_000), new Money(5_000), 2, 5, 15), new Money(13_000));

        $this->assertSame(10_000, $preview->downPaymentCents);
        $this->assertSame([['number' => 1, 'cents' => 3_000, 'covers' => false]], $preview->allocations);
        $this->assertSame([LayawayStatus::PendingDownPayment, LayawayStatus::Active], [$preview->statusBefore, $preview->statusAfter]);
        $this->assertSame('Activará el apartado con la inicial de $100,00; se aplicará a la cuota 1.', $preview->summary());
    }

    public function test_paying_everything_completes_it_and_keeps_the_excess_unassigned(): void
    {
        $preview = $this->preview(new LayawayTerms(new Money(20_000), new Money(5_000), 1, 5, 15), new Money(25_000));

        $this->assertSame(5_000, $preview->unallocatedCents);
        $this->assertSame(LayawayStatus::Completed, $preview->statusAfter);
        $this->assertCount(5, $preview->allocations);
    }

    public function test_without_down_payment_it_only_pays_installments(): void
    {
        $preview = $this->preview(new LayawayTerms(new Money(20_000), new Money(0), 1, 4, 15), new Money(5_000));

        $this->assertSame(0, $preview->downPaymentCents);
        $this->assertSame(LayawayStatus::Active, $preview->statusBefore);
        $this->assertSame([['number' => 1, 'cents' => 5_000, 'covers' => true]], $preview->allocations);
    }

    public function test_bolivars_are_converted_with_the_given_rate(): void
    {
        $preview = $this->preview(new LayawayTerms(new Money(20_000), new Money(5_000), 1, 5, 15), Money::fromDecimalString('2125.00', Currency::VES), new ManualRate('42.50'));

        $this->assertSame(5_000, $preview->amount->cents);
    }

    public function test_less_than_the_down_payment_is_an_installment_toward_it_without_writing(): void
    {
        $preview = $this->preview(new LayawayTerms(new Money(20_000), new Money(5_000), 1, 5, 15), new Money(2_000));

        $this->assertSame([2_000, 3_000], [$preview->downPaymentCents, $preview->downPaymentMissingCents]);
        $this->assertSame([], $preview->allocations);
        $this->assertFalse($preview->activates());
        $this->assertSame([LayawayStatus::PendingDownPayment, LayawayStatus::PendingDownPayment], [$preview->statusBefore, $preview->statusAfter]);
        $this->assertSame('Abonará $20,00 a la inicial y faltarán $30,00 para activar el apartado.', $preview->summary());
        $this->assertSame([0, 0], [DB::table('layaways')->count(), DB::table('payments')->count()]);
    }

    private function preview(LayawayTerms $terms, Money $settled, ?ManualRate $rate = null): ApprovalPreview
    {
        return app(OpeningPaymentPreview::class)->for($terms, $settled, $rate);
    }
}
