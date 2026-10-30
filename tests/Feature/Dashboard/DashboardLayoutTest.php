<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\DashboardLayout;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class DashboardLayoutTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const CARDS = ['unreadMessages', 'pendingReceipts', 'collectedThisMonth', 'customersOverdue', 'longOverdue', 'completedNotDelivered'];

    private Organization $organization;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->owner = $this->within(fn (): User => User::factory()->owner()->create(['email_verified_at' => now()]));
        $this->member = $this->within(fn (): User => User::factory()->create(['email_verified_at' => now()]));
        $this->withoutOrganization();
    }

    public function test_without_a_saved_layout_the_dashboard_is_stacked_in_the_default_order(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/Dashboard')
            ->where('layout', ['mode' => 'stacked', 'sections' => DashboardLayout::SECTIONS, 'cards' => DashboardLayout::CARDS]));
    }

    public function test_the_layout_is_saved_per_user(): void
    {
        $this->actingAs($this->owner)
            ->from(route('dashboard'))
            ->put(route('dashboard.layout'), ['mode' => 'split', 'sections' => ['plan', 'stats'], 'cards' => self::CARDS])
            ->assertRedirect(route('dashboard'));

        $this->assertEquals(
            ['mode' => 'split', 'sections' => ['plan', 'stats'], 'cards' => self::CARDS],
            $this->within(fn (): mixed => $this->owner->fresh()?->dashboard_layout),
        );

        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layout.mode', 'split')
            ->where('layout.sections', ['plan', 'stats'])
            ->where('layout.cards', self::CARDS));

        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layout.mode', 'stacked')
            ->where('layout.cards', DashboardLayout::CARDS));
    }

    public function test_an_incomplete_or_repeated_order_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put(route('dashboard.layout'), ['mode' => 'split', 'sections' => ['plan', 'plan'], 'cards' => self::CARDS])
            ->assertSessionHasErrors('sections.0');

        $this->actingAs($this->owner)
            ->put(route('dashboard.layout'), ['mode' => 'grid', 'sections' => ['plan', 'stats'], 'cards' => array_slice(self::CARDS, 1)])
            ->assertSessionHasErrors(['mode', 'cards']);

        $this->actingAs($this->owner)
            ->put(route('dashboard.layout'), ['mode' => 'stacked', 'sections' => ['plan', 'stats'], 'cards' => [...array_slice(self::CARDS, 1), 'dueThisWeek']])
            ->assertSessionHasErrors('cards.5');

        $this->assertNull($this->within(fn (): mixed => $this->owner->fresh()?->dashboard_layout));
    }

    public function test_a_guest_cannot_save_a_layout(): void
    {
        $this->put(route('dashboard.layout'), ['mode' => 'split', 'sections' => ['plan', 'stats'], 'cards' => self::CARDS])
            ->assertRedirect();

        $this->assertNull($this->within(fn (): mixed => $this->owner->fresh()?->dashboard_layout));
    }

    public function test_a_stored_layout_with_unknown_or_missing_cards_is_repaired(): void
    {
        $this->within(fn () => $this->owner->forceFill(['dashboard_layout' => [
            'mode' => 'split',
            'sections' => ['plan'],
            'cards' => ['dueThisWeek', 'unreadMessages', 'unreadMessages', 'pendingReceipts'],
        ]])->save());

        $layout = DashboardLayout::of($this->within(fn (): ?User => $this->owner->fresh()));

        $this->assertSame('split', $layout->mode);
        $this->assertSame(['plan', 'stats'], $layout->sections);
        $this->assertSame(['unreadMessages', 'pendingReceipts', 'collectedThisMonth', 'customersOverdue', 'longOverdue', 'completedNotDelivered'], $layout->cards);
    }

    public function test_a_stored_layout_with_an_unknown_mode_falls_back_to_the_default(): void
    {
        $this->within(fn () => $this->owner->forceFill(['dashboard_layout' => ['mode' => 'grid', 'sections' => [], 'cards' => []]])->save());

        $this->assertEquals(new DashboardLayout, DashboardLayout::of($this->within(fn (): ?User => $this->owner->fresh())));
        $this->assertEquals(new DashboardLayout, DashboardLayout::of(null));
    }

    public function test_the_layout_refuses_a_partial_order(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DashboardLayout('stacked', ['stats'], DashboardLayout::CARDS);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
