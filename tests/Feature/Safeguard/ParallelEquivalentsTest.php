<?php

declare(strict_types=1);

namespace Tests\Feature\Safeguard;

use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Safeguard\ParallelEquivalents;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ParallelEquivalentsTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-10 12:00:00');
        $this->organization = Organization::factory()->create(['timezone' => 'America/Caracas']);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '1000.00000000', 'published_at' => CarbonImmutable::parse('2026-11-03 04:00:00')]);
        ExchangeRate::factory()->create(['source' => RateSource::Parallel, 'rate' => '1050.00000000', 'published_at' => CarbonImmutable::parse('2026-11-10 09:00:00')]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_los_bolivares_cobrados_al_bcv_valen_menos_en_paralelo_con_la_tasa_del_dia_del_pago(): void
    {
        $equivalent = $this->equivalentOf([
            'amount_cents_usd' => new Money(10_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '89000.00',
            'rate_applied' => '890.00000000',
            'paid_on' => '2026-11-03',
        ]);

        $this->assertSame(8_900, $equivalent->equivalent?->cents);
        $this->assertSame(-1_100, $equivalent->gap()?->cents);
        $this->assertSame('1000.00000000', $equivalent->parallelRate);
    }

    public function test_sin_fecha_de_pago_usa_el_dia_en_que_se_registro_y_hoy_la_tasa_vigente(): void
    {
        $equivalent = $this->equivalentOf([
            'amount_cents_usd' => new Money(10_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '89000.00',
            'settled_at' => CarbonImmutable::now(),
        ]);

        $this->assertSame('1050.00000000', $equivalent->parallelRate);
    }

    public function test_un_pago_en_dolares_o_ya_cobrado_al_paralelo_vale_lo_mismo_que_lo_acreditado(): void
    {
        $dollars = $this->equivalentOf(['amount_cents_usd' => new Money(3_000)]);
        $parallel = $this->equivalentOf([
            'amount_cents_usd' => new Money(2_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '21000.00',
            'rate_applied' => '1050.00000000',
            'rate_source' => RateSource::Parallel,
        ]);

        $this->assertSame(3_000, $dollars->equivalent?->cents);
        $this->assertSame(2_000, $parallel->equivalent?->cents);
        $this->assertSame(0, $parallel->gap()?->cents);
    }

    public function test_un_pago_anterior_al_historico_paralelo_queda_sin_equivalente(): void
    {
        $equivalent = $this->equivalentOf([
            'amount_cents_usd' => new Money(10_000),
            'declared_currency' => Currency::VES,
            'declared_amount' => '3650.00',
            'paid_on' => '2025-06-02',
        ]);

        $this->assertFalse($equivalent->known());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function equivalentOf(array $attributes): \App\Domain\Safeguard\ParallelEquivalent
    {
        $payment = app(TenantContext::class)->runFor($this->organization, fn (): Payment => Payment::factory()->create($attributes));

        return app(ParallelEquivalents::class)->of($payment, $this->organization);
    }
}
