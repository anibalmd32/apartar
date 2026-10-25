<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Subscription\SubscriptionStatus;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BillsSubscriptions;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PastDueBannerTest extends TestCase
{
    use BillsSubscriptions, ReusesMigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-03 10:00:00');
        $this->setUpBilling();
        $this->invoiceFor(['issued_at' => '2026-10-25 06:00:00']);
        $this->invoiceFor(['issued_at' => '2026-10-25 06:00:00', 'kind' => 'plan_change', 'amount_cents' => 3_900, 'paid_cents' => 1_000, 'period_start' => '2026-10-20 00:00:00']);
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => '2026-11-08 23:59:59']));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_backoffice_page_carries_the_amount_and_the_deadline(): void
    {
        $this->actingAs($this->billedOwner)
            ->get('/backoffice/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pastDue.amount', '$44,00')
                ->where('pastDue.deadline', '8 de noviembre'));
    }

    public function test_the_owner_gets_the_link_to_pay(): void
    {
        $this->actingAs($this->billedOwner)
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pastDue.invoicesUrl', route('subscription.invoices.index')));
    }

    public function test_a_member_sees_the_notice_without_the_amount_or_the_link(): void
    {
        $member = $this->billed(fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($member)
            ->get('/backoffice/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pastDue.amount', null)
                ->where('pastDue.invoicesUrl', null)
                ->where('pastDue.deadline', '8 de noviembre'));
    }

    public function test_a_deadline_already_gone_is_not_announced(): void
    {
        CarbonImmutable::setTestNow('2026-11-10 10:00:00');

        $this->actingAs($this->billedOwner)
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pastDue.deadline', null)
                ->where('pastDue.amount', '$44,00'));
    }

    public function test_the_deadline_is_in_the_business_calendar(): void
    {
        $this->billedOrganization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $this->billed(fn () => Subscription::query()->update(['grace_ends_at' => CarbonImmutable::parse('2026-11-08 23:59:59', 'Pacific/Kiritimati')->setTimezone('America/Caracas')]));
        $this->withoutOrganization();

        $this->actingAs($this->billedOwner)
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pastDue.deadline', '8 de noviembre'));
    }

    public function test_up_to_date_there_is_no_notice(): void
    {
        $this->billed(fn () => Subscription::query()->update(['status' => SubscriptionStatus::Active, 'grace_ends_at' => null]));
        $this->withoutOrganization();

        $this->actingAs($this->billedOwner)
            ->get('/backoffice/productos')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pastDue', null));
    }

    public function test_a_team_member_of_another_business_never_gets_it(): void
    {
        $catalog = $this->billed(function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($stranger)
            ->get('/c/'.$catalog->slug)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pastDue', null));
    }
}
