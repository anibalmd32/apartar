<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Exchange\RateSource;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentReallocationPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const REASON = 'El cliente pactó abonar la cuota siguiente.';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        ExchangeRate::factory()->create(['source' => RateSource::Bcv, 'rate' => '42.50000000', 'published_at' => CarbonImmutable::now()->subHour()]);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_layaway_view_brings_the_editor(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        $installmentOne = $this->installmentId($layaway, 1);

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reallocation.installments', 5)
            ->where('reallocation.installments.0', ['id' => $installmentOne, 'number' => 1, 'amountCents' => 3_000, 'paidCents' => 3_000])
            ->has('reallocation.payments', 1)
            ->where('reallocation.payments.0.url', route('payments.reallocate', $payment))
            ->where('reallocation.payments.0.label', '$60,00 del 10 de septiembre')
            ->where('reallocation.payments.0.allocations', [['installmentId' => $installmentOne, 'cents' => 3_000], ['installmentId' => $this->installmentId($layaway, 2), 'cents' => 3_000]]));

        foreach ([LayawayStatus::Cancelled, LayawayStatus::Expired] as $closed) {
            DB::table('layaways')->where('id', $layaway->getKey())->update(['status' => $closed->value]);
            $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page->where('reallocation', null));
        }

        $onlyDownPayment = $this->layawayIn($this->organization);
        $this->post(route('layaways.payments.store', $onlyDownPayment), [
            'method' => 'efectivo_usd', 'amount' => '50.00', 'currency' => 'USD', 'paid_at' => '2026-09-10',
            'note' => 'Pago en efectivo recibido en tienda por Ana.', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('layaways.show', $onlyDownPayment));
        $this->withoutOrganization();
        $this->get(route('layaways.show', $onlyDownPayment))->assertInertia(fn (AssertableInertia $page) => $page->where('reallocation', null));
    }

    public function test_reallocating_returns_to_the_layaway(): void
    {
        [$layaway, $payment] = $this->paidLayaway();

        $this->actingAs($this->member)
            ->patch(route('payments.reallocate', $payment), $this->payload($layaway, ['1' => '30.00', '3' => '30']))
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', 'Pago reasignado: $30,00 a la cuota 1 y $30,00 a la cuota 3.');

        $this->withoutOrganization();
        $this->patch(route('payments.reallocate', $payment), [...$this->payload($layaway, [3 => '20.00', 2 => '20.00', 1 => '20.00']), 'reason' => self::REASON])
            ->assertSessionHas('status', 'Pago reasignado: $20,00 a la cuota 1, $20,00 a la cuota 2 y $20,00 a la cuota 3.');
        $this->withoutOrganization();
        $this->patch(route('payments.reallocate', $payment), $this->payload($layaway, [1 => '30.00', 3 => '30.00']))->assertRedirect(route('layaways.show', $layaway));

        $this->assertSame(['3000', '0', '3000'], DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->limit(3)->pluck('paid_cents')->map(fn (mixed $cents): string => (string) $cents)->all());
    }

    public function test_each_refusal_comes_back_in_its_field(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        $this->actingAs($this->member)->from(route('layaways.show', $layaway));

        $this->patch(route('payments.reallocate', $payment), $this->payload($layaway, ['1' => '30.00', '3' => '20.00']))
            ->assertSessionHasErrors(['allocations' => 'El reparto nuevo suma $50,00 y el pago tiene $60,00 repartidos: faltan $10,00. Se reasigna la aplicación del pago, no su importe.']);
        $this->withoutOrganization();
        $this->patch(route('payments.reallocate', $payment), $this->payload($layaway, ['1' => '60.00']))
            ->assertSessionHasErrors(['allocations' => 'La cuota 1 admite como mucho $30,00 de este pago: no se puede pagar una cuota de más.']);
        $this->patch(route('payments.reallocate', $payment), [...$this->payload($layaway, ['1' => '30.00', '3' => '30.00']), 'reason' => 'Corto'])
            ->assertSessionHasErrors('reason');
        $this->patch(route('payments.reallocate', $payment), $this->payload($layaway, ['1' => '30,00', '3' => '30.00']))
            ->assertSessionHasErrors('allocations.0.amount');
        $repeated = $this->payload($layaway, ['1' => '30.00', '3' => '30.00']);
        $repeated['allocations'][1]['installment'] = $repeated['allocations'][0]['installment'];
        $this->patch(route('payments.reallocate', $payment), $repeated)->assertSessionHasErrors('allocations.1.installment');

        $this->assertSame(['3000', '3000', '0'], DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->limit(3)->pluck('paid_cents')->map(fn (mixed $cents): string => (string) $cents)->all());
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        [$layaway, $payment] = $this->paidLayaway();
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->member)
            ->patch(route('payments.reallocate', $payment), $this->payload($layaway, ['2' => '30.00', '3' => '30.00']))
            ->assertRedirect(route('layaways.show', $layaway));
    }

    /**
     * @return array{Layaway, Payment}
     */
    private function paidLayaway(): array
    {
        $layaway = $this->layawayIn($this->organization);
        $this->actingAs($this->member);

        foreach (['50.00', '60.00'] as $amount) {
            $this->post(route('layaways.payments.store', $layaway), [
                'method' => 'efectivo_usd', 'amount' => $amount, 'currency' => 'USD', 'paid_at' => '2026-09-10',
                'note' => 'Pago en efectivo recibido en tienda por Ana.', 'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect(route('layaways.show', $layaway));
            $this->withoutOrganization();
        }

        $payment = $this->withinOrganization($this->organization, fn (): Payment => Payment::query()->where('layaway_id', $layaway->getKey())->latest('id')->firstOrFail());
        $this->withoutOrganization();

        return [$layaway, $payment];
    }

    /**
     * @param  array<int, string>  $amountsByNumber
     * @return array<string, mixed>
     */
    private function payload(Layaway $layaway, array $amountsByNumber): array
    {
        return [
            'allocations' => array_map(fn (int $number): array => [
                'installment' => $this->installmentId($layaway, $number),
                'amount' => $amountsByNumber[$number] ?? '',
            ], range(1, 5)),
            'reason' => self::REASON,
        ];
    }

    private function installmentId(Layaway $layaway, int $number): int
    {
        return (int) DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', $number)->value('id');
    }
}
