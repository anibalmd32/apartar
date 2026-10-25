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
    }

    private function active(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));

        return $layaway;
    }

    private function nextOf(Layaway $layaway): ?NextPayment
    {
        return $this->withinOrganization($this->organization, function () use ($layaway): ?NextPayment {
            /** @var Layaway $fresh */
            $fresh = Layaway::query()->findOrFail($layaway->getKey());

            return NextPayment::for($fresh, Installment::query()->where('layaway_id', $fresh->getKey())->orderBy('number')->get()->all());
        });
    }
}
