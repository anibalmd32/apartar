<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentApplier;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class PaymentApplierIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_layaway_of_another_organization_cannot_receive_a_payment(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $neighbours = $this->layawayIn($theirs);
        $staff = $this->withinOrganization($ours, fn (): User => User::factory()->create());

        try {
            $this->withinOrganization($ours, fn () => app(PaymentApplier::class)->apply($neighbours, new Money(5_000), null, '50.00', Currency::USD, null, $staff, null));
            $this->fail('Se aplicó un pago al apartado de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $neighbours->getKey())->value('status'));
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('installments')->count());
    }

    public function test_the_payment_and_its_allocations_are_born_in_the_organization_of_the_layaway(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $ours = Organization::factory()->create();
        $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $staff = $this->withinOrganization($ours, fn (): User => User::factory()->create());

        $this->withinOrganization($ours, fn () => app(PaymentApplier::class)->apply($mine, new Money(11_000), null, '110.00', Currency::USD, null, $staff, null));

        $this->assertSame([(int) $ours->getKey()], DB::table('payments')->distinct()->pluck('organization_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $ours->getKey()], DB::table('payment_allocations')->distinct()->pluck('organization_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, DB::table('payment_allocations')->count());
    }
}
