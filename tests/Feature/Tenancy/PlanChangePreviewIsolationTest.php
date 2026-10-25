<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Subscription\ChangePlanAction;
use App\Domain\Subscription\PlanChangePreview;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanChangePreviewIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_neighbour_usage_never_shows_as_our_excess_and_its_plan_is_untouched(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'position' => 1, 'max_catalogs' => 1, 'max_products' => 2, 'max_products_per_catalog' => 1]);
        $pro = Plan::factory()->unlimited()->create(['code' => 'pro', 'position' => 2]);

        $ours = Organization::factory()->create();
        $owner = $this->withinOrganization($ours, function () use ($pro): User {
            Subscription::factory()->forPlan($pro)->paid()->create();

            return User::factory()->owner()->create();
        });
        $this->recalculateUsage($ours);

        $neighbour = $this->createNeighbourOrganization();
        $this->withinOrganization($neighbour, function () use ($pro): void {
            Subscription::factory()->forPlan($pro)->paid()->create();
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->count(3)->create()->modelKeys());
            Catalog::factory()->published()->create();
        });
        $this->recalculateUsage($neighbour);

        $preview = $this->withinOrganization($ours, fn (): PlanChangePreview => PlanChangePreview::forDowngrade(Subscription::query()->with('plan')->firstOrFail(), $free));

        $this->assertSame([], $preview->excesses, 'Los contadores del vecino no son los de este negocio.');
        $this->assertSame(0, $preview->catalogsOverLimitCount, 'Ni sus catálogos.');

        app(ChangePlanAction::class)->handle($ours, $free, $owner);

        $this->assertNull($this->withinOrganization($neighbour, fn (): ?int => Subscription::query()->firstOrFail()->scheduled_plan_id));
        $this->assertSame($free->getKey(), $this->withinOrganization($ours, fn (): ?int => Subscription::query()->firstOrFail()->scheduled_plan_id));
    }
}
