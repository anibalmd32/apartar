<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationNotActiveException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OrganizationClosurePageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->owner = $this->withinOrganization($this->organization, function (): User {
            $this->subscribeWithoutLimits();

            return User::factory()->owner()->create();
        });
        $this->withoutOrganization();
    }

    public function test_the_owner_closes_and_is_taken_out(): void
    {
        $this->actingAs($this->owner)
            ->post(route('organization.close'), ['confirmed' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertSame(OrganizationStatus::Closed, $this->organization->fresh()?->status);

        $this->withoutOrganization();
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => OrganizationNotActiveException::CLOSED_MESSAGE]);
        $this->assertGuest();
    }

    public function test_with_layaways_in_progress_the_list_comes_back(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->postJson(route('organization.close'), ['confirmed' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.close.0', fn (string $message): bool => str_contains($message, $layaway->code));

        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
    }

    public function test_it_needs_the_confirmation(): void
    {
        $this->actingAs($this->owner)
            ->post(route('organization.close'), ['confirmed' => false])
            ->assertSessionHasErrors(['confirmed' => 'Confirma que quieres cerrar el negocio.']);

        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
    }

    public function test_a_member_cannot_close(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($member)->post(route('organization.close'), ['confirmed' => true])->assertForbidden();

        $this->assertSame(OrganizationStatus::Active, $this->organization->fresh()?->status);
    }

    public function test_a_suspended_business_can_still_close(): void
    {
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended, 'suspended_at' => now()])->save();

        $this->actingAs($this->owner)
            ->post(route('organization.close'), ['confirmed' => true])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(OrganizationStatus::Closed, $this->organization->fresh()?->status);
    }

    public function test_the_subscription_page_offers_closing_to_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('closeUrl', route('organization.close')));
    }
}
