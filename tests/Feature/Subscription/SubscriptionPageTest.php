<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SubscriptionPageTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->owner = $this->withinOrganization($this->organization, function (): User {
            Plan::factory()->unlimited()->withEveryFeature()->create(['code' => 'pro-pg', 'name' => 'Pro', 'position' => 2]);
            Subscription::factory()->forPlan(Plan::factory()->create([
                'code' => 'free-pg', 'name' => 'Free', 'position' => 1, 'max_products' => 10, 'features' => PlanFeature::noneEnabled(),
            ]))->create();

            Product::factory()->count(8)->create();

            return User::factory()->owner()->create();
        });

        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();
    }

    public function test_the_owner_sees_the_plan_its_usage_and_what_unlocks_more(): void
    {
        $this->actingAs($this->owner)
            ->get('/backoffice/suscripcion')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/subscription/Show')
                ->where('planState.planName', 'Free')
                ->where('planState.usage.products.used', 8)
                ->where('planState.usage.products.percentage', 80)
                ->where('planState.upgrades.products.planName', 'Pro')
                ->missing('planState.upgrades.users')
                ->where('planState.featureUpgrades.excel_export.planName', 'Pro')
                ->where('planState.featureLabels.excel_export', PlanFeature::ExcelExport->label())
                ->where('planState.metricLabels.products', UsageMetric::Products->label()));
    }

    public function test_during_the_downgrade_grace_it_says_what_will_be_unpublished_and_when(): void
    {
        $this->withinOrganization($this->organization, fn () => Catalog::factory()->count(7)->published()->create());
        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingUnpublish', null));

        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['downgrade_applied_at' => '2026-11-01 00:05:00']));
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pendingUnpublish.excess', 2)
                ->where('pendingUnpublish.limit', 5)
                ->where('pendingUnpublish.unpublishAt', fn (string $iso): bool => CarbonImmutable::parse($iso)->equalTo(CarbonImmutable::parse('2026-11-08 00:05:00'))));

        $this->withinOrganization($this->organization, fn () => Catalog::query()->limit(2)->update(['published_at' => null]));
        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingUnpublish', null));

        $this->withinOrganization($this->organization, function (): void {
            Catalog::query()->update(['published_at' => now()->subDay()]);
            Subscription::query()->firstOrFail()->plan->forceFill(['max_catalogs' => null])->save();
        });
        $this->recalculateUsage($this->organization);
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pendingUnpublish', null));
    }

    public function test_it_survives_the_suspension(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)->get('/backoffice/suscripcion')->assertOk();
    }

    public function test_a_member_cannot_open_it(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        $this->actingAs($member)->get('/backoffice/suscripcion')->assertForbidden();
    }

    public function test_a_team_member_of_another_business_never_gets_this_plan_on_its_public_pages(): void
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($stranger)
            ->get('/c/'.$catalog->slug)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('planState', null));
    }

    public function test_every_backoffice_page_carries_the_plan_state(): void
    {
        $this->actingAs($this->owner)
            ->get('/backoffice/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('planState.usage.products.used', 8));
    }
}
