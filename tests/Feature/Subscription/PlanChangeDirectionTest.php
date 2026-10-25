<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanChangeDirection;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanChangeDirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_compares_positions_and_not_prices(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1]);
        $pro = Plan::factory()->create(['code' => 'pro', 'position' => 2, 'price_cents' => 0]);
        $business = Plan::factory()->create(['code' => 'business', 'position' => 3, 'price_cents' => 0]);

        $this->assertSame(PlanChangeDirection::Upgrade, PlanChangeDirection::between($free, $pro));
        $this->assertSame(PlanChangeDirection::Upgrade, PlanChangeDirection::between($pro, $business), 'A igual precio decide la posición.');
        $this->assertSame(PlanChangeDirection::Downgrade, PlanChangeDirection::between($business, $pro));
        $this->assertSame(PlanChangeDirection::Same, PlanChangeDirection::between($pro, $pro->fresh() ?? $pro));
    }

    public function test_free_is_the_floor_and_a_tie_is_a_downgrade(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1]);
        $negotiated = Plan::factory()->hidden()->create(['position' => 0]);
        $twin = Plan::factory()->create(['position' => 0]);

        $this->assertSame(PlanChangeDirection::Downgrade, PlanChangeDirection::between($negotiated, $free));
        $this->assertSame(PlanChangeDirection::Downgrade, PlanChangeDirection::between($negotiated, $twin));
        $this->assertSame(PlanChangeDirection::Downgrade, PlanChangeDirection::between($twin, $negotiated));
    }
}
