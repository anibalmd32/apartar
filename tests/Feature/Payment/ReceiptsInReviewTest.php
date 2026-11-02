<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Money\Currency;
use App\Domain\Payment\ReceiptsInReview;
use App\Domain\Payment\ReceiptStatus;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReceiptsInReviewTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-02 10:00:00');
        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);
        $this->layaway = $this->layawayIn($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sin_comprobantes_en_revision_no_hay_nada_que_descontar(): void
    {
        $this->receiptFor($this->layaway, ReceiptStatus::Rejected, ['declared_amount' => '30.00', 'declared_currency' => Currency::USD]);
        $this->receiptFor($this->layaway, ReceiptStatus::Approved, ['declared_amount' => '30.00', 'declared_currency' => Currency::USD]);

        $inReview = $this->inReview();

        $this->assertFalse($inReview->any());
        $this->assertSame(0, $inReview->amount->cents);
        $this->assertNull($inReview->lastSubmittedAt);
    }

    public function test_suma_en_dolares_los_comprobantes_en_revision_con_la_tasa_del_dia_del_pago(): void
    {
        ExchangeRate::factory()->create(['rate' => '40.00000000', 'published_at' => CarbonImmutable::parse('2026-10-30 09:00:00')]);
        ExchangeRate::factory()->create(['rate' => '50.00000000', 'published_at' => CarbonImmutable::parse('2026-11-02 09:00:00')]);

        $this->receiptFor($this->layaway, ReceiptStatus::PendingReview, [
            'declared_amount' => '12.50',
            'declared_currency' => Currency::USD,
            'created_at' => CarbonImmutable::parse('2026-10-31 08:00:00'),
        ]);
        $this->receiptFor($this->layaway, ReceiptStatus::PendingReview, [
            'declared_amount' => '400.00',
            'declared_currency' => Currency::VES,
            'paid_at' => '2026-10-30',
            'created_at' => CarbonImmutable::parse('2026-11-01 21:44:00'),
        ]);
        $this->receiptFor($this->layawayIn($this->organization), ReceiptStatus::PendingReview, ['declared_amount' => '99.00', 'declared_currency' => Currency::USD]);

        $inReview = $this->inReview();

        $this->assertTrue($inReview->any());
        $this->assertSame(2, $inReview->count);
        $this->assertSame(2_250, $inReview->amount->cents);
        $this->assertSame('2026-11-01 21:44', $inReview->lastSubmittedAt?->format('Y-m-d H:i'));
    }

    private function inReview(): ReceiptsInReview
    {
        return $this->withinOrganization($this->organization, fn (): ReceiptsInReview => ReceiptsInReview::of(
            $this->layaway,
            $this->organization,
            app(ExchangeRateService::class),
        ));
    }
}
