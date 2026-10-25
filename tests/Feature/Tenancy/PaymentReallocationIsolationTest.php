<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentReallocationIsolationTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const REASON = 'El cliente pactó abonar la cuota siguiente.';

    public function test_the_neighbour_payment_is_not_found_and_nothing_is_written(): void
    {
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $theirs = $this->subscribe($this->neighbourOrganization());
        [$layaway, $payment] = $this->paid($theirs);

        $ours = $this->subscribe(Organization::factory()->create());
        $intruder = $this->withinOrganization($ours, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($intruder)->patch(route('payments.reallocate', $payment), [
            'allocations' => [['installment' => $this->installmentId($layaway, 2), 'amount' => '30.00']],
            'reason' => self::REASON,
        ])->assertNotFound();

        $this->assertSame([1], $this->allocatedNumbers($payment));
    }

    public function test_a_neighbour_installment_cannot_receive_our_payment(): void
    {
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $ours = $this->subscribe(Organization::factory()->create());
        $member = $this->withinOrganization($ours, fn (): User => User::factory()->create());
        [$layaway, $payment] = $this->paid($ours);
        [$theirLayaway] = $this->paid($this->subscribe($this->neighbourOrganization()));
        $this->withoutOrganization();

        $this->actingAs($member)->from(route('layaways.show', $layaway))->patch(route('payments.reallocate', $payment), [
            'allocations' => [['installment' => $this->installmentId($theirLayaway, 2), 'amount' => '30.00']],
            'reason' => self::REASON,
        ])->assertSessionHasErrors('allocations');

        $this->assertSame([1], $this->allocatedNumbers($payment));
        $this->assertSame('0', (string) DB::table('installments')->where('layaway_id', $theirLayaway->getKey())->where('number', 2)->value('paid_cents'));
    }

    /**
     * @return array{Layaway, Payment}
     */
    private function paid(Organization $organization): array
    {
        $layaway = $this->layawayIn($organization);
        $staff = $this->withinOrganization($organization, fn (): User => User::factory()->create());
        $register = fn (string $amount): Payment => $this->withinOrganization($organization, fn (): Payment => app(RegisterManualPaymentAction::class)->handle(
            $layaway,
            new ManualPaymentData(PaymentMethod::EfectivoUsd, null, $amount, Currency::USD, null, CarbonImmutable::now(), 'Pago recibido en tienda por Ana.', (string) Str::uuid()),
            $staff,
        ));

        $register('50.00');

        return [$layaway, $register('30.00')];
    }

    private function installmentId(Layaway $layaway, int $number): int
    {
        return (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', $number)->value('id');
    }

    /**
     * @return list<int>
     */
    private function allocatedNumbers(Payment $payment): array
    {
        return DB::table('payment_allocations')->join('installments', 'installments.id', '=', 'payment_allocations.installment_id')
            ->where('payment_id', $payment->getKey())->orderBy('installments.number')->pluck('installments.number')->map(fn (mixed $number): int => (int) $number)->all();
    }
}
