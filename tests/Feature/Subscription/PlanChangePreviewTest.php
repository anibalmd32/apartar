<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que la pantalla enseña antes de cambiar de plan (`REQ-SUB-005` RB-5,
 * `T-068`). Lo que excede y su consecuencia los prueba `ChangePlanActionTest`
 * junto a la acción que los exige; aquí, la fecha y la forma con la que viaja.
 */
final class PlanChangePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Plan $free;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-09 12:00:00');
        $this->free = Plan::factory()->create(['code' => 'free', 'name' => 'Free', 'position' => 1]);
        $this->pro = Plan::factory()->withEveryFeature()->create(['code' => 'pro', 'name' => 'Pro', 'position' => 2]);
        $this->organization = Organization::factory()->create();
        $this->withinOrganization($this->organization, fn () => Subscription::factory()->forPlan($this->pro)->create([
            'current_period_end' => CarbonImmutable::parse('2026-10-20 12:00:00'),
        ]));
        $this->recalculateUsage($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * RB-3 · la bajada cae al fin del período pagado; sin período pagado
     * —vencido, o con la factura sin pagar—, ya.
     */
    public function test_the_effective_date_is_the_end_of_the_paid_period(): void
    {
        $this->assertEquals(CarbonImmutable::parse('2026-10-20 12:00:00'), PlanChangePreview::effectiveDateOf($this->subscription()));

        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue]));
        $this->assertEquals(CarbonImmutable::now(), PlanChangePreview::effectiveDateOf($this->subscription()));
    }

    /** Subir dice lo que se gana y que aplica ya; al mismo plan, nada. */
    public function test_the_shape_it_travels_with(): void
    {
        $downgrade = $this->withinOrganization($this->organization, fn (): array => PlanChangePreview::of($this->subscription(), $this->free)->toArray());

        $this->assertSame('downgrade', $downgrade['direction']);
        $this->assertTrue(CarbonImmutable::parse('2026-10-20 12:00:00')->equalTo(CarbonImmutable::parse($downgrade['effectiveAt'])), 'Viaja como instante ISO.');
        $this->assertSame([], $downgrade['excesses']);
        $this->assertNull($downgrade['productsPerCatalog']);
        $this->assertSame(array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases()), $downgrade['lostFeatures']);

        $same = $this->withinOrganization($this->organization, fn (): array => PlanChangePreview::of($this->subscription(), $this->pro)->toArray());
        $this->assertSame('same', $same['direction']);
        $this->assertNull($same['effectiveAt']);
        $this->assertSame([], $same['gainedFeatures']);
    }

    private function subscription(): Subscription
    {
        return $this->withinOrganization($this->organization, fn (): Subscription => Subscription::query()->with('plan')->firstOrFail());
    }
}
