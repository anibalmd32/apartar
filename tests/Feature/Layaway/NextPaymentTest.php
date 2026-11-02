<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\NextPayment;
use App\Domain\Layaway\NextPaymentKind;
use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class NextPaymentTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_layaway_waiting_for_its_down_payment_suggests_the_down_payment(): void
    {
        $next = $this->nextOf($this->layawayIn($this->organization));

        $this->assertNotNull($next);
        $this->assertSame(NextPaymentKind::DownPayment, $next->kind);
        $this->assertNull($next->installmentNumber);
        $this->assertSame(5_000, $next->amount->cents);
    }

    public function test_a_down_payment_paid_in_part_suggests_what_is_missing(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['paid_cents' => 2_000, 'remaining_cents' => 18_000]);

        $next = $this->nextOf($layaway);

        $this->assertNotNull($next);
        $this->assertSame(NextPaymentKind::DownPayment, $next->kind);
        $this->assertSame(3_000, $next->amount->cents);
        $this->assertTrue($next->started);
        $this->assertFalse($this->nextOf($this->layawayIn($this->organization))?->started);
    }

    public function test_a_zero_down_payment_suggests_nothing(): void
    {
        $layaway = $this->layawayIn($this->organization, terms: new LayawayTerms(new Money(20_000), new Money(0), 1, 5, 15));

        $this->assertNull($this->nextOf($layaway));
    }

    public function test_an_active_layaway_suggests_what_the_oldest_uncovered_installment_lacks(): void
    {
        $layaway = $this->active();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 2)->update(['paid_cents' => 1_000, 'status' => 'partially_paid']);

        $next = $this->nextOf($layaway);

        $this->assertNotNull($next);
        $this->assertSame(NextPaymentKind::Installment, $next->kind);
        $this->assertSame(2, $next->installmentNumber);
        $this->assertSame(2_000, $next->amount->cents);
    }

    public function test_nothing_is_suggested_when_the_layaway_does_not_collect_or_nothing_is_left(): void
    {
        $this->assertNull($this->nextOf($this->layawayIn($this->organization, LayawayStatus::Cancelled)));

        $layaway = $this->active();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->update(['paid_cents' => 3_000, 'status' => 'paid']);
        $this->assertNull($this->nextOf($layaway));
        $this->assertNull($this->nextOf($layaway, '2026-12-31', 3_000));
    }

    public function test_las_cuotas_vencidas_se_sugieren_juntas_con_la_mas_antigua_como_alternativa(): void
    {
        $layaway = $this->active();

        $next = $this->nextOf($layaway, '2026-10-15');

        $this->assertNotNull($next);
        $this->assertSame(NextPaymentKind::Overdue, $next->kind);
        $this->assertNull($next->installmentNumber);
        $this->assertSame(2, $next->overdueInstallments);
        $this->assertSame(6_000, $next->amount->cents);
        $this->assertNotNull($next->alternative);
        $this->assertSame(NextPaymentKind::Installment, $next->alternative->kind);
        $this->assertSame(1, $next->alternative->installmentNumber);
        $this->assertSame(3_000, $next->alternative->amount->cents);
    }

    public function test_una_sola_cuota_vencida_no_trae_alternativa_y_la_que_vence_hoy_no_esta_vencida(): void
    {
        $layaway = $this->active();

        $overdue = $this->nextOf($layaway, '2026-09-26');
        $this->assertSame(NextPaymentKind::Overdue, $overdue?->kind);
        $this->assertSame(1, $overdue->overdueInstallments);
        $this->assertSame(3_000, $overdue->amount->cents);
        $this->assertNull($overdue->alternative);

        $dueToday = $this->nextOf($layaway, '2026-09-25');
        $this->assertSame(NextPaymentKind::Installment, $dueToday?->kind);
        $this->assertSame(1, $dueToday->installmentNumber);
    }

    public function test_los_comprobantes_en_revision_se_descuentan_de_lo_vencido(): void
    {
        $layaway = $this->active();

        $part = $this->nextOf($layaway, '2026-10-15', 1_000);
        $this->assertSame(NextPaymentKind::Overdue, $part?->kind);
        $this->assertSame(5_000, $part->amount->cents);
        $this->assertSame(1_000, $part->inReview->cents);
        $this->assertSame(2_000, $part->alternative?->amount->cents);
        $this->assertFalse($part->covered());

        $firstCovered = $this->nextOf($layaway, '2026-10-15', 3_500);
        $this->assertSame(2_500, $firstCovered?->amount->cents);
        $this->assertNull($firstCovered->alternative);

        $all = $this->nextOf($layaway, '2026-10-15', 6_000);
        $this->assertTrue($all?->covered());
        $this->assertSame(0, $all->amount->cents);
        $this->assertSame(NextPaymentKind::Overdue, $all->kind);
        $this->assertNull($all->alternative);
    }

    public function test_un_comprobante_en_revision_que_cubre_la_proxima_cuota_no_deja_nada_por_sugerir(): void
    {
        $layaway = $this->active();

        $next = $this->nextOf($layaway, '2026-09-12', 3_000);

        $this->assertSame(NextPaymentKind::Installment, $next?->kind);
        $this->assertSame(1, $next->installmentNumber);
        $this->assertTrue($next->covered());
        $this->assertSame(3_000, $next->inReview->cents);

        $partial = $this->nextOf($layaway, '2026-09-12', 1_000);
        $this->assertSame(2_000, $partial?->amount->cents);
        $this->assertTrue($partial->started);
    }

    public function test_un_comprobante_en_revision_se_descuenta_de_la_inicial(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $part = $this->nextOf($layaway, null, 2_000);
        $this->assertSame(NextPaymentKind::DownPayment, $part?->kind);
        $this->assertSame(3_000, $part->amount->cents);
        $this->assertTrue($part->started);

        $this->assertTrue($this->nextOf($layaway, null, 5_000)?->covered());
    }

    public function test_an_impossible_suggestion_cannot_be_built(): void
    {
        foreach ([
            [NextPaymentKind::Installment, 1, 0],
            [NextPaymentKind::Installment, null, 3_000],
            [NextPaymentKind::DownPayment, 1, 5_000],
        ] as [$kind, $number, $cents]) {
            try {
                new NextPayment($kind, $number, new Money($cents));
                $this->fail(sprintf('Se construyó %s %s de %d centavos.', $kind->value, var_export($number, true), $cents));
            } catch (InvalidArgumentException) {
            }
        }

        foreach ([
            fn () => new NextPayment(NextPaymentKind::Overdue, null, new Money(6_000)),
            fn () => new NextPayment(NextPaymentKind::Overdue, 1, new Money(6_000), overdueInstallments: 2),
            fn () => new NextPayment(NextPaymentKind::Installment, 1, new Money(3_000), overdueInstallments: 2),
            fn () => new NextPayment(NextPaymentKind::Overdue, null, new Money(3_000), overdueInstallments: 2, alternative: new NextPayment(NextPaymentKind::Installment, 1, new Money(3_000))),
            fn () => new NextPayment(NextPaymentKind::Installment, 1, new Money(-1), inReview: new Money(3_000)),
        ] as $index => $build) {
            try {
                $build();
                $this->fail(sprintf('Se construyó la sugerencia imposible %d.', $index));
            } catch (InvalidArgumentException) {
            }
        }
    }

    private function active(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));

        return $layaway;
    }

    private function nextOf(Layaway $layaway, ?string $today = null, int $inReview = 0): ?NextPayment
    {
        return $this->withinOrganization($this->organization, function () use ($layaway, $today, $inReview): ?NextPayment {
            /** @var Layaway $fresh */
            $fresh = Layaway::query()->findOrFail($layaway->getKey());

            return NextPayment::for(
                $fresh,
                Installment::query()->where('layaway_id', $fresh->getKey())->orderBy('number')->get()->all(),
                CarbonImmutable::parse($today ?? '2026-09-10'),
                new Money($inReview),
            );
        });
    }
}
